<?php

namespace App\Services\Events;

class FindLocalMetros
{
    /** @var list<string> */
    public const SLUGS = [
        'ann-arbor',
        'atlanta',
        'austin',
        'baltimore',
        'bangor',
        'bloomington',
        'boise',
        'boston',
        'brattleboro',
        'buffalo',
        'burlington',
        'cape-cod',
        'charleston',
        'charlotte',
        'chicago',
        'cincinnati',
        'cleveland',
        'columbia-mo',
        'columbus',
        'dallas',
        'denver',
        'des-moines',
        'detroit',
        'duluth',
        'fort-collins',
        'grand-rapids',
        'green-bay',
        'hanover',
        'harrisburg',
        'hartford',
        'hilton-head',
        'houston',
        'hudson-valley',
        'indianapolis',
        'iowa-city',
        'jersey-shore',
        'kansas-city',
        'lansing',
        'las-vegas',
        'lincoln',
        'london',
        'los-angeles',
        'madison',
        'manchester',
        'miami',
        'milwaukee',
        'minneapolis',
        'nashville',
        'new-haven',
        'new-orleans',
        'new-york',
        'northampton',
        'oklahoma-city',
        'orlando',
        'philadelphia',
        'phoenix',
        'pittsburgh',
        'pittsfield',
        'portland',
        'portland-me',
        'portsmouth',
        'providence',
        'raleigh',
        'rochester',
        'rockland',
        'rutland',
        'sacramento',
        'salt-lake-city',
        'san-antonio',
        'san-diego',
        'san-francisco',
        'santa-barbara',
        'seattle',
        'sonoma',
        'spokane',
        'st-louis',
        'stamford',
        'tampa',
        'traverse-city',
        'tucson',
        'washington',
        'wenatchee',
        'wilmington-nc',
        'worcester',
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'nyc' => 'new-york',
        'new-york-city' => 'new-york',
        'la' => 'los-angeles',
        'l-a' => 'los-angeles',
        'washington-dc' => 'washington',
        'washington-d-c' => 'washington',
        'dc' => 'washington',
        'd-c' => 'washington',
        'sf' => 'san-francisco',
        'saint-louis' => 'st-louis',
        'portland-maine' => 'portland-me',
        'wilmington-north-carolina' => 'wilmington-nc',
        'columbia-missouri' => 'columbia-mo',
    ];

    public static function slugFor(string $city): ?string
    {
        $slug = self::match(self::normalize($city));

        if ($slug !== null) {
            return $slug;
        }

        $head = trim(explode(',', $city, 2)[0]);

        if ($head === '' || $head === trim($city)) {
            return null;
        }

        return self::match(self::normalize($head));
    }

    private static function match(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $slug = self::ALIASES[$slug] ?? $slug;

        return in_array($slug, self::SLUGS, true) ? $slug : null;
    }

    private static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? null : $value;
    }
}
