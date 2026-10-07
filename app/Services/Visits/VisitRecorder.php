<?php

namespace App\Services\Visits;

use App\Models\PageVisit;
use App\Models\User;
use DeviceDetector\Cache\LaravelCache;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VisitRecorder
{
    private const DEDUPE_SECONDS = 30;

    public function record(Request $request): ?PageVisit
    {
        $path = $this->normalizePath((string) $request->input('path', ''));

        if ($path === null) {
            return null;
        }

        /** @var User|null $user */
        $user = $request->user();
        $ip = $this->clip($request->ip(), 45);

        if ($this->isDuplicate($path, $user, $ip)) {
            return null;
        }

        $userAgent = $this->clip((string) $request->userAgent(), 1024) ?? '';
        $detected = $this->detect($userAgent);

        return PageVisit::query()->create([
            'user_id' => $user?->id,
            'occurred_at' => now(),
            'path' => $path,
            'referrer' => $this->clip($request->input('referrer'), 2048),
            'ip_address' => $ip,
            'user_agent' => $userAgent === '' ? null : $userAgent,
            'browser' => $detected['browser'],
            'browser_version' => $detected['browser_version'],
            'platform' => $detected['platform'],
            'platform_version' => $detected['platform_version'],
            'device' => $detected['device'],
            'device_type' => $detected['device_type'],
            'is_robot' => $detected['is_robot'],
            'language' => $this->clip($request->input('language'), 64),
            'timezone' => $this->clip($request->input('timezone'), 64),
            'screen' => $this->clip($request->input('screen'), 32),
            'plan' => $this->planFor($user),
        ]);
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            throw ValidationException::withMessages([
                'path' => ['A page path is required.'],
            ]);
        }

        if (str_contains($path, '://')) {
            $parsed = parse_url($path);
            $path = is_array($parsed) ? (string) ($parsed['path'] ?? '') : '';
        }

        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        $path = '/'.ltrim($path, '/');

        if ($path === '/' || $path === '') {
            $path = '/';
        }

        if (str_contains($path, '\\') || str_contains($path, '..')) {
            throw ValidationException::withMessages([
                'path' => ['That page path is not valid.'],
            ]);
        }

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return null;
        }

        return $this->clip($path, 2048);
    }

    private function isDuplicate(string $path, ?User $user, ?string $ip): bool
    {
        $query = PageVisit::query()
            ->where('path', $path)
            ->where('occurred_at', '>=', now()->subSeconds(self::DEDUPE_SECONDS));

        if ($user !== null) {
            $query->where('user_id', $user->id);
        } else {
            $query->whereNull('user_id')->where('ip_address', $ip);
        }

        return $query->exists();
    }

    private function planFor(?User $user): string
    {
        if ($user === null) {
            return PageVisit::PLAN_GUEST;
        }

        return $user->isPro() ? PageVisit::PLAN_PRO : PageVisit::PLAN_FREE;
    }

    /**
     * @return array{
     *     browser: ?string,
     *     browser_version: ?string,
     *     platform: ?string,
     *     platform_version: ?string,
     *     device: ?string,
     *     device_type: ?string,
     *     is_robot: bool
     * }
     */
    private function detect(string $userAgent): array
    {
        $empty = [
            'browser' => null,
            'browser_version' => null,
            'platform' => null,
            'platform_version' => null,
            'device' => null,
            'device_type' => null,
            'is_robot' => false,
        ];

        if ($userAgent === '') {
            return $empty;
        }

        try {
            $detector = new DeviceDetector($userAgent);
            $detector->setCache(new LaravelCache);
            $detector->parse();
        } catch (\Throwable) {
            return $empty;
        }

        if ($detector->isBot()) {
            $bot = $detector->getBot();
            $name = is_array($bot) ? (string) ($bot['name'] ?? 'Bot') : 'Bot';

            return [
                'browser' => $this->clip($name, 64),
                'browser_version' => null,
                'platform' => null,
                'platform_version' => null,
                'device' => $this->clip($name, 128),
                'device_type' => 'robot',
                'is_robot' => true,
            ];
        }

        $client = $detector->getClient();
        $os = $detector->getOs();
        $clientName = is_array($client) ? (string) ($client['name'] ?? '') : '';
        $clientVersion = is_array($client) ? (string) ($client['version'] ?? '') : '';
        $osName = is_array($os) ? (string) ($os['name'] ?? '') : '';
        $osVersion = is_array($os) ? (string) ($os['version'] ?? '') : '';
        $brandCode = $detector->getBrand();
        $brand = $brandCode !== '' ? AbstractDeviceParser::getFullName($brandCode) : '';
        $model = $detector->getModel();
        $device = trim($brand.' '.$model);
        $deviceType = $detector->getDeviceName();

        return [
            'browser' => $this->clip($clientName, 64),
            'browser_version' => $this->clip($clientVersion, 32),
            'platform' => $this->clip($osName, 64),
            'platform_version' => $this->clip($osVersion, 32),
            'device' => $this->clip($device !== '' ? $device : $deviceType, 128),
            'device_type' => $this->clip($deviceType, 32),
            'is_robot' => false,
        ];
    }

    private function clip(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }
}
