<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AppSettings
{
    public const FREE_PLANS_PER_DAY = 'free_plans_per_day';

    public const ANTHROPIC_API_KEY = 'anthropic_api_key';

    public const ANTHROPIC_MODEL = 'anthropic_model';

    public const ANTHROPIC_URL = 'anthropic_url';

    public const MAIL_FROM_ADDRESS = 'mail_from_address';

    public const MAIL_FROM_NAME = 'mail_from_name';

    public const BOOKING_OPS_EMAIL = 'booking_ops_email';

    public const RATE_LIMIT_AI_PER_HOUR = 'rate_limit_ai_per_hour';

    public const PRO_MONTHLY_PRICE_CENTS = 'pro_monthly_price_cents';

    public const PRO_CURRENCY = 'pro_currency';

    public const APP_STORE_URL = 'app_store_url';

    public const PLAY_STORE_URL = 'play_store_url';

    public const WEB_APP_URL = 'web_app_url';

    public const STRIPE_SECRET = 'stripe_secret';

    public const STRIPE_PUBLISHABLE_KEY = 'stripe_publishable_key';

    public const STRIPE_WEBHOOK_SECRET = 'stripe_webhook_secret';

    public const STRIPE_FAKE = 'stripe_fake';

    /** Non-secret defaults always present in the settings map. */
    public const DEFAULTS = [
        self::FREE_PLANS_PER_DAY => 5,
        self::PRO_MONTHLY_PRICE_CENTS => 999,
        self::PRO_CURRENCY => 'usd',
    ];

    /** Keys that may be stored as overrides; empty/null means fall back to env. */
    public const OVERRIDABLE = [
        self::ANTHROPIC_API_KEY,
        self::ANTHROPIC_MODEL,
        self::ANTHROPIC_URL,
        self::MAIL_FROM_ADDRESS,
        self::MAIL_FROM_NAME,
        self::BOOKING_OPS_EMAIL,
        self::RATE_LIMIT_AI_PER_HOUR,
        self::PRO_MONTHLY_PRICE_CENTS,
        self::PRO_CURRENCY,
        self::APP_STORE_URL,
        self::PLAY_STORE_URL,
        self::WEB_APP_URL,
        self::STRIPE_SECRET,
        self::STRIPE_PUBLISHABLE_KEY,
        self::STRIPE_WEBHOOK_SECRET,
        self::STRIPE_FAKE,
    ];

    public const SECRETS = [
        self::ANTHROPIC_API_KEY,
        self::STRIPE_SECRET,
        self::STRIPE_WEBHOOK_SECRET,
    ];

    private const CACHE_KEY = 'plnr.app_settings';

    private const CACHE_TTL_SECONDS = 60;

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->stored();

        if (! array_key_exists($key, $stored)) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }

        return $this->unwrap($key, $stored[$key]);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * True when an admin override row exists for this key (even if empty string after unwrap).
     */
    public function hasOverride(string $key): bool
    {
        return array_key_exists($key, $this->stored());
    }

    /**
     * Resolve string: admin override if non-empty, else env/config fallback.
     */
    public function resolveString(string $key, ?string $envFallback): ?string
    {
        if ($this->hasOverride($key)) {
            $override = $this->get($key);

            if (is_string($override) && trim($override) !== '') {
                return trim($override);
            }

            // Explicit empty override clears to "use nothing" only for secrets cleared;
            // for non-secrets empty override means fall back to env.
            if (! in_array($key, self::SECRETS, true)) {
                return $this->normalizeNullable($envFallback);
            }

            return $this->normalizeNullable($envFallback);
        }

        return $this->normalizeNullable($envFallback);
    }

    public function resolveInt(string $key, int $envFallback): int
    {
        if ($this->hasOverride($key)) {
            return (int) $this->get($key, $envFallback);
        }

        return $envFallback;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $settings = self::DEFAULTS;

        foreach ($this->stored() as $key => $raw) {
            if (in_array($key, self::SECRETS, true)) {
                continue;
            }

            $settings[$key] = $this->unwrap($key, $raw);
        }

        return $settings;
    }

    /**
     * Admin API payload: public values + masked secret metadata + env fallbacks.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(): array
    {
        $settings = $this->all();

        $settings[self::ANTHROPIC_API_KEY.'_set'] = $this->anthropicApiKey() !== null;
        $settings[self::ANTHROPIC_API_KEY.'_source'] = $this->sourceFor(
            self::ANTHROPIC_API_KEY,
            config('services.anthropic.key'),
        );
        $settings[self::ANTHROPIC_API_KEY.'_hint'] = $this->maskSecret($this->anthropicApiKey());

        $settings[self::ANTHROPIC_MODEL] = $this->anthropicModel();
        $settings[self::ANTHROPIC_MODEL.'_source'] = $this->sourceFor(
            self::ANTHROPIC_MODEL,
            config('services.anthropic.model'),
        );
        $settings[self::ANTHROPIC_MODEL.'_env'] = config('services.anthropic.model');

        $settings[self::ANTHROPIC_URL] = $this->anthropicUrl();
        $settings[self::ANTHROPIC_URL.'_source'] = $this->sourceFor(
            self::ANTHROPIC_URL,
            config('services.anthropic.url'),
        );
        $settings[self::ANTHROPIC_URL.'_env'] = config('services.anthropic.url');

        $settings[self::MAIL_FROM_ADDRESS] = $this->mailFromAddress();
        $settings[self::MAIL_FROM_ADDRESS.'_source'] = $this->sourceFor(
            self::MAIL_FROM_ADDRESS,
            config('mail.from.address'),
        );
        $settings[self::MAIL_FROM_ADDRESS.'_env'] = config('mail.from.address');

        $settings[self::MAIL_FROM_NAME] = $this->mailFromName();
        $settings[self::MAIL_FROM_NAME.'_source'] = $this->sourceFor(
            self::MAIL_FROM_NAME,
            config('mail.from.name'),
        );
        $settings[self::MAIL_FROM_NAME.'_env'] = config('mail.from.name');

        $settings[self::BOOKING_OPS_EMAIL] = $this->bookingOpsEmail();
        $settings[self::BOOKING_OPS_EMAIL.'_source'] = $this->sourceFor(
            self::BOOKING_OPS_EMAIL,
            config('services.booking.ops_email'),
        );
        $settings[self::BOOKING_OPS_EMAIL.'_env'] = config('services.booking.ops_email');

        $settings[self::RATE_LIMIT_AI_PER_HOUR] = $this->rateLimitAiPerHour();
        $settings[self::RATE_LIMIT_AI_PER_HOUR.'_source'] = $this->sourceFor(
            self::RATE_LIMIT_AI_PER_HOUR,
            (string) config('rate_limiting.ai_per_hour'),
        );
        $settings[self::RATE_LIMIT_AI_PER_HOUR.'_env'] = (int) config('rate_limiting.ai_per_hour');

        $settings[self::PRO_MONTHLY_PRICE_CENTS] = $this->proMonthlyPriceCents();
        $settings[self::PRO_MONTHLY_PRICE_CENTS.'_source'] = $this->sourceFor(
            self::PRO_MONTHLY_PRICE_CENTS,
            (string) config('services.pro.monthly_price_cents'),
        );
        $settings[self::PRO_MONTHLY_PRICE_CENTS.'_env'] = (int) config('services.pro.monthly_price_cents');

        $settings[self::PRO_CURRENCY] = $this->proCurrency();
        $settings[self::PRO_CURRENCY.'_source'] = $this->sourceFor(
            self::PRO_CURRENCY,
            config('services.pro.currency'),
        );
        $settings[self::PRO_CURRENCY.'_env'] = config('services.pro.currency');

        $settings[self::APP_STORE_URL] = $this->appStoreUrl();
        $settings[self::APP_STORE_URL.'_source'] = $this->sourceFor(
            self::APP_STORE_URL,
            config('services.pro.app_store_url'),
        );
        $settings[self::APP_STORE_URL.'_env'] = config('services.pro.app_store_url');

        $settings[self::PLAY_STORE_URL] = $this->playStoreUrl();
        $settings[self::PLAY_STORE_URL.'_source'] = $this->sourceFor(
            self::PLAY_STORE_URL,
            config('services.pro.play_store_url'),
        );
        $settings[self::PLAY_STORE_URL.'_env'] = config('services.pro.play_store_url');

        $settings[self::WEB_APP_URL] = $this->webAppUrl();
        $settings[self::WEB_APP_URL.'_source'] = $this->sourceFor(
            self::WEB_APP_URL,
            config('services.pro.web_app_url'),
        );
        $settings[self::WEB_APP_URL.'_env'] = config('services.pro.web_app_url');

        $settings[self::STRIPE_SECRET.'_set'] = $this->stripeSecret() !== null;
        $settings[self::STRIPE_SECRET.'_source'] = $this->sourceFor(
            self::STRIPE_SECRET,
            config('services.stripe.secret'),
        );
        $settings[self::STRIPE_SECRET.'_hint'] = $this->maskSecret($this->stripeSecret());

        $settings[self::STRIPE_PUBLISHABLE_KEY] = $this->stripePublishableKey();
        $settings[self::STRIPE_PUBLISHABLE_KEY.'_source'] = $this->sourceFor(
            self::STRIPE_PUBLISHABLE_KEY,
            config('services.stripe.publishable'),
        );
        $settings[self::STRIPE_PUBLISHABLE_KEY.'_env'] = config('services.stripe.publishable');

        $settings[self::STRIPE_WEBHOOK_SECRET.'_set'] = $this->stripeWebhookSecret() !== null;
        $settings[self::STRIPE_WEBHOOK_SECRET.'_source'] = $this->sourceFor(
            self::STRIPE_WEBHOOK_SECRET,
            config('services.stripe.webhook_secret'),
        );
        $settings[self::STRIPE_WEBHOOK_SECRET.'_hint'] = $this->maskSecret($this->stripeWebhookSecret());

        $settings[self::STRIPE_FAKE] = $this->stripeFake();
        $settings[self::STRIPE_FAKE.'_source'] = $this->hasOverride(self::STRIPE_FAKE) ? 'admin' : 'env';
        $settings[self::STRIPE_FAKE.'_env'] = (bool) config('services.stripe.fake', false);

        return $settings;
    }

    public function set(string $key, mixed $value): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $this->encode($key, $value)],
        );

        $this->forgetCache();
    }

    public function forget(string $key): void
    {
        AppSetting::query()->where('key', $key)->delete();
        $this->forgetCache();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function freePlansPerDay(): int
    {
        return max(0, $this->getInt(self::FREE_PLANS_PER_DAY, 5));
    }

    public function anthropicApiKey(): ?string
    {
        return $this->resolveString(self::ANTHROPIC_API_KEY, config('services.anthropic.key'));
    }

    public function anthropicModel(): string
    {
        return $this->resolveString(
            self::ANTHROPIC_MODEL,
            (string) config('services.anthropic.model', 'claude-sonnet-4-6'),
        ) ?? 'claude-sonnet-4-6';
    }

    public function anthropicUrl(): string
    {
        return $this->resolveString(
            self::ANTHROPIC_URL,
            (string) config('services.anthropic.url', 'https://api.anthropic.com/v1/messages'),
        ) ?? 'https://api.anthropic.com/v1/messages';
    }

    public function mailFromAddress(): ?string
    {
        return $this->resolveString(self::MAIL_FROM_ADDRESS, config('mail.from.address'));
    }

    public function mailFromName(): ?string
    {
        return $this->resolveString(self::MAIL_FROM_NAME, config('mail.from.name'));
    }

    public function bookingOpsEmail(): ?string
    {
        $fallback = config('services.booking.ops_email');

        return $this->resolveString(
            self::BOOKING_OPS_EMAIL,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function rateLimitAiPerHour(): int
    {
        return max(1, $this->resolveInt(
            self::RATE_LIMIT_AI_PER_HOUR,
            (int) config('rate_limiting.ai_per_hour', 20),
        ));
    }

    public function proMonthlyPriceCents(): int
    {
        return max(100, $this->resolveInt(
            self::PRO_MONTHLY_PRICE_CENTS,
            (int) config('services.pro.monthly_price_cents', 999),
        ));
    }

    public function proCurrency(): string
    {
        return strtolower($this->resolveString(
            self::PRO_CURRENCY,
            (string) config('services.pro.currency', 'usd'),
        ) ?? 'usd');
    }

    public function appStoreUrl(): ?string
    {
        $fallback = config('services.pro.app_store_url');

        return $this->resolveString(
            self::APP_STORE_URL,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function playStoreUrl(): ?string
    {
        $fallback = config('services.pro.play_store_url');

        return $this->resolveString(
            self::PLAY_STORE_URL,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function webAppUrl(): ?string
    {
        $fallback = config('services.pro.web_app_url');

        return $this->resolveString(
            self::WEB_APP_URL,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function stripeSecret(): ?string
    {
        $fallback = config('services.stripe.secret');

        return $this->resolveString(
            self::STRIPE_SECRET,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function stripePublishableKey(): ?string
    {
        $fallback = config('services.stripe.publishable');

        return $this->resolveString(
            self::STRIPE_PUBLISHABLE_KEY,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function stripeWebhookSecret(): ?string
    {
        $fallback = config('services.stripe.webhook_secret');

        return $this->resolveString(
            self::STRIPE_WEBHOOK_SECRET,
            is_string($fallback) ? $fallback : null,
        );
    }

    public function stripeFake(): bool
    {
        if ($this->hasOverride(self::STRIPE_FAKE)) {
            $override = $this->get(self::STRIPE_FAKE);

            if (is_bool($override)) {
                return $override;
            }

            if (is_int($override) || is_float($override)) {
                return (int) $override === 1;
            }

            if (is_string($override)) {
                return filter_var($override, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return (bool) config('services.stripe.fake', false)
            || filter_var(env('STRIPE_FAKE', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $settings = [];

            foreach (AppSetting::query()->get(['key', 'value']) as $row) {
                $decoded = json_decode((string) $row->value, true);
                $settings[$row->key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
            }

            return $settings;
        });
    }

    private function encode(string $key, mixed $value): string
    {
        if (in_array($key, self::SECRETS, true) && is_string($value) && $value !== '') {
            return json_encode([
                '__encrypted' => Crypt::encryptString($value),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function unwrap(string $key, mixed $raw): mixed
    {
        if (is_array($raw) && isset($raw['__encrypted']) && is_string($raw['__encrypted'])) {
            try {
                return Crypt::decryptString($raw['__encrypted']);
            } catch (Throwable) {
                return null;
            }
        }

        return $raw;
    }

    private function sourceFor(string $key, mixed $envFallback): string
    {
        if ($this->hasOverride($key)) {
            $override = $this->get($key);

            if (is_string($override) && trim($override) !== '') {
                return 'admin';
            }

            if (is_int($override) || is_float($override)) {
                return 'admin';
            }
        }

        if (is_string($envFallback) && trim($envFallback) !== '') {
            return 'env';
        }

        if (is_int($envFallback) || is_float($envFallback)) {
            return 'env';
        }

        return 'none';
    }

    private function maskSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $len = strlen($value);

        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 4).str_repeat('•', max(4, $len - 8)).substr($value, -4);
    }

    private function normalizeNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
