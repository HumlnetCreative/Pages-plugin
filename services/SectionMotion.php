<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;

/** Validates and normalizes the deliberately small section reveal contract. */
final class SectionMotion
{
    public const EFFECTS = ['none', 'fade', 'fade-up', 'fade-left', 'fade-right', 'scale-in'];
    public const DURATIONS = ['fast' => 300, 'normal' => 500, 'slow' => 700];
    public const DELAYS = [0, 100, 200, 300, 400];
    public const STAGGER_STEP_MS = 75;
    public const STAGGER_MAX_ITEMS = 7;

    public static function defaults(): array
    {
        return [
            'effect' => 'none',
            'duration' => 'normal',
            'duration_ms' => self::DURATIONS['normal'],
            'delay_ms' => 0,
            'stagger_items' => false,
        ];
    }

    /** @throws \ValidationException */
    public static function normalize(string $type, mixed $value): array
    {
        if ($value === null || $value === []) {
            return self::defaults();
        }
        if (!is_array($value)) {
            throw new \ValidationException(['style.motion' => 'Nastavení pohybu musí být mapování.']);
        }

        $unknown = array_diff(array_keys($value), ['effect', 'duration', 'delay_ms', 'stagger_items']);
        if ($unknown) {
            throw new \ValidationException(['style.motion' => 'Nastavení pohybu obsahuje neznámé volby.']);
        }

        $effect = is_string($value['effect'] ?? null) ? $value['effect'] : 'none';
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new \ValidationException(['style.motion.effect' => 'Neplatný efekt pohybu.']);
        }
        if ($effect === 'none') {
            return self::defaults();
        }

        $registry = SectionRegistry::instance();
        if (!$registry->supportsMotion($type)) {
            throw new \ValidationException(['style.motion.effect' => 'Tento typ sekce pohybové efekty nepodporuje.']);
        }

        $duration = is_string($value['duration'] ?? null) ? $value['duration'] : 'normal';
        if (!array_key_exists($duration, self::DURATIONS)) {
            throw new \ValidationException(['style.motion.duration' => 'Neplatná rychlost pohybu.']);
        }

        $rawDelay = $value['delay_ms'] ?? 0;
        $delay = is_int($rawDelay) || (is_string($rawDelay) && ctype_digit($rawDelay)) ? (int) $rawDelay : -1;
        if (!in_array($delay, self::DELAYS, true)) {
            throw new \ValidationException(['style.motion.delay_ms' => 'Neplatné zpoždění pohybu.']);
        }

        $rawStagger = $value['stagger_items'] ?? false;
        if (!in_array($rawStagger, [false, true, 0, 1, '0', '1'], true)) {
            throw new \ValidationException(['style.motion.stagger_items' => 'Neplatné nastavení postupného odhalení položek.']);
        }
        $stagger = in_array($rawStagger, [true, 1, '1'], true);
        if ($stagger && !$registry->supportsMotionStagger($type)) {
            throw new \ValidationException(['style.motion.stagger_items' => 'Tento typ sekce nepodporuje postupné odhalení položek.']);
        }

        return [
            'effect' => $effect,
            'duration' => $duration,
            'duration_ms' => self::DURATIONS[$duration],
            'delay_ms' => $delay,
            'stagger_items' => $stagger,
        ];
    }

    /** Normalizes persisted data and removes the no-op default from section JSON. */
    public static function normalizeSection(Section $section): void
    {
        $style = (array) $section->style;
        $motion = self::normalize((string) $section->type, $style['motion'] ?? []);
        if ($motion['effect'] === 'none') {
            unset($style['motion']);
        }
        else {
            $style['motion'] = [
                'effect' => $motion['effect'],
                'duration' => $motion['duration'],
                'delay_ms' => $motion['delay_ms'],
                'stagger_items' => $motion['stagger_items'],
            ];
        }
        $section->style = $style;
    }

    /** Rendering must fail open if legacy or externally supplied JSON is invalid. */
    public static function presentation(Section $section): array
    {
        try {
            return self::normalize((string) $section->type, data_get($section->style, 'motion', []));
        }
        catch (\ValidationException) {
            return self::defaults();
        }
    }
}
