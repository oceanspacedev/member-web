<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * Login panel admin lewat WhatsApp OTP — memakai OtpService & gateway yang sama dengan aplikasi mobile.
 * Hanya user ber-peran "admin" (User::canAccessPanel) yang dikirimi kode.
 *
 * @property-read Schema $form
 */
class WhatsAppLogin extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public bool $awaitingOtp = false;

    /**
     * Login WhatsApp hanya ditawarkan bila gateway WAGHub sudah dikonfigurasi (WAG_URL & WAG_TOKEN).
     */
    public static function isAvailable(): bool
    {
        return filled(config('loyalty.whatsapp.waghub.url')) && filled(config('loyalty.whatsapp.waghub.token'));
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if (! static::isAvailable()) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $this->maxWidth = 'full';
        $this->form->fill();
    }

    public function submit(): void
    {
        abort_unless(static::isAvailable(), 404);

        try {
            $this->rateLimit(10);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title(__('filament-panels::auth/pages/login.notifications.throttled.title', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => $exception->minutesUntilAvailable,
                ]))
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $phone = Phone::normalize($data['phone'] ?? null);

        if (! $phone) {
            throw ValidationException::withMessages(['data.phone' => 'Nomor WhatsApp tidak valid.']);
        }

        $admin = User::where('phone', $phone)->first();
        $allowed = $admin && $admin->canAccessPanel(Filament::getCurrentOrDefaultPanel());

        $this->awaitingOtp
            ? $this->verifyCode($phone, (string) ($data['code'] ?? ''), $allowed ? $admin : null)
            : $this->sendCode($phone, $allowed);
    }

    private function sendCode(string $phone, bool $allowed): void
    {
        // Nomor non-admin tidak dikirimi OTP, tetapi tampilannya sama agar daftar admin tidak bisa ditebak.
        if ($allowed) {
            try {
                app(OtpService::class)->send($phone);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['data.phone' => collect($e->errors())->flatten()->first()]);
            }
        }

        $this->awaitingOtp = true;
        $this->form->fill(['phone' => $phone, 'code' => null]);

        Notification::make()
            ->title('Jika nomor terdaftar sebagai admin, kode OTP sudah dikirim ke WhatsApp.')
            ->success()
            ->send();
    }

    private function verifyCode(string $phone, string $code, ?User $admin): void
    {
        if (! $admin) {
            throw ValidationException::withMessages(['data.code' => 'Kode OTP salah atau sudah kedaluwarsa.']);
        }

        try {
            app(OtpService::class)->verify($phone, $code);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.code' => collect($e->errors())->flatten()->first()]);
        }

        Filament::auth()->login($admin, remember: true);
        session()->regenerate();

        $this->redirectIntended(Filament::getUrl());
    }

    public function changePhone(): void
    {
        $this->awaitingOtp = false;
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label('Nomor WhatsApp')
                ->tel()
                ->placeholder('0812 3456 7890')
                ->autocomplete('tel')
                ->required()
                ->maxLength(20)
                ->autofocus()
                ->disabled(fn (): bool => $this->awaitingOtp)
                ->dehydrated(),
            TextInput::make('code')
                ->label('Kode OTP')
                ->helperText('Masukkan 6 digit kode yang dikirim ke WhatsApp.')
                // Bukan ->numeric(): itu mengubah state jadi angka sehingga kode berawalan 0 (mis. 012345) ditolak.
                ->rule('digits:6')
                ->maxLength(6)
                ->inputMode('numeric')
                ->autocomplete('one-time-code')
                ->autofocus()
                ->required()
                ->visible(fn (): bool => $this->awaitingOtp),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submit')
                ->footer([
                    Actions::make([
                        Action::make('submit')
                            ->label(fn (): string => $this->awaitingOtp ? 'Verifikasi dan masuk' : 'Kirim kode OTP')
                            ->submit('submit'),
                    ])
                        ->fullWidth()
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Masuk dengan WhatsApp';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Masuk dengan WhatsApp';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->awaitingOtp) {
            return Action::make('changePhone')
                ->link()
                ->label('Ganti nomor atau kirim ulang kode')
                ->action('changePhone');
        }

        return Action::make('backToLogin')
            ->link()
            ->label('Kembali ke halaman masuk')
            ->icon(Heroicon::ArrowLeft)
            ->url(Filament::getLoginUrl());
    }

    public function getView(): string
    {
        return 'filament.auth.whatsapp-login';
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
