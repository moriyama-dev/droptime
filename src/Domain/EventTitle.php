<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DropTime\Support\Lang;

/**
 * DB の events 行を人が読む表記にする。テンプレートに散らばらないよう集約。
 */
final class EventTitle
{
    /** @param array<string, mixed> $event */
    public static function of(array $event): string
    {
        $stroke = Stroke::tryFrom((string) $event['stroke']);

        $parts = [];
        $parts[] = self::genderLabel((string) $event['gender']);

        if (!empty($event['age_group'])) {
            $parts[] = (string) $event['age_group'];
        }

        $parts[] = $event['distance'] . 'm';
        $parts[] = ($event['is_relay'] ?? 0) ? self::relayLabel($stroke) : ($stroke?->label() ?? (string) $event['stroke']);

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $event */
    public static function withNumber(array $event): string
    {
        $title = self::of($event);
        $round = self::roundLabel((string) $event['round']);

        return Lang::t('evt.prefix') . ' ' . $event['event_number'] . ' ' . $title
            . ($round === '' ? '' : Lang::t('evt.round_wrap', $round));
    }

    public static function genderLabel(string $gender): string
    {
        return Lang::t(match ($gender) {
            'M' => 'gender.M',
            'F' => 'gender.F',
            default => 'gender.X',
        });
    }

    /** 決勝だけの大会が大半なので、timed のときは表記を出さない。 */
    public static function roundLabel(string $round): string
    {
        return match ($round) {
            'prelim' => Lang::t('round.prelim'),
            'semi' => Lang::t('round.semi'),
            'final' => Lang::t('round.final'),
            default => '',
        };
    }

    private static function relayLabel(?Stroke $stroke): string
    {
        return match ($stroke) {
            Stroke::FREE => Lang::t('relay.free'),
            Stroke::MEDLEY, Stroke::IM => Lang::t('relay.medley'),
            default => Lang::t('relay.generic'),
        };
    }
}
