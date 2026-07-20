<?php

namespace App\Support\Mail;

final class PlanTypeMailTheme
{
    /**
     * @return array{
     *     slug: string,
     *     label: string,
     *     accent: string,
     *     accentSoft: string,
     *     surface: string,
     *     softBg: string,
     *     text: string,
     *     muted: string,
     *     border: string,
     *     motif: string
     * }
     */
    public static function for(?string $slug): array
    {
        $normalized = $slug ?? 'night_out';

        return match ($normalized) {
            'date_night' => [
                'slug' => 'date_night',
                'label' => 'Date Night',
                'accent' => '#C45C8A',
                'accentSoft' => '#F7D6E4',
                'surface' => '#FFFFFF',
                'softBg' => '#FBF0F5',
                'text' => '#2A1F24',
                'muted' => '#7A5C68',
                'border' => '#E8C9D6',
                'motif' => 'heart',
            ],
            'vacation' => [
                'slug' => 'vacation',
                'label' => 'Vacation',
                'accent' => '#3D8B7A',
                'accentSoft' => '#C9E6DF',
                'surface' => '#FFFFFF',
                'softBg' => '#EEF7F4',
                'text' => '#1C2B27',
                'muted' => '#4F6B63',
                'border' => '#BFD9D1',
                'motif' => 'wave',
            ],
            'road_trip' => [
                'slug' => 'road_trip',
                'label' => 'Road Trip',
                'accent' => '#4A6FA5',
                'accentSoft' => '#CDD9EC',
                'surface' => '#FFFFFF',
                'softBg' => '#EEF2F8',
                'text' => '#1C2433',
                'muted' => '#55627A',
                'border' => '#C5D0E2',
                'motif' => 'road',
            ],
            default => [
                'slug' => 'night_out',
                'label' => 'Night Out',
                'accent' => '#D4622A',
                'accentSoft' => '#F5D4C0',
                'surface' => '#FFFFFF',
                'softBg' => '#FBF3ED',
                'text' => '#1A1A1A',
                'muted' => '#6B5C52',
                'border' => '#E4DDD4',
                'motif' => 'moon',
            ],
        };
    }

    public static function accent(?string $slug): string
    {
        return self::for($slug)['accent'];
    }

    public static function label(?string $slug): string
    {
        return self::for($slug)['label'];
    }
}
