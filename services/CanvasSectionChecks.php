<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;

/** Read-only editorial checks shown by the structural Canvas. */
final class CanvasSectionChecks
{
    public function forSection(Section $section): array
    {
        $issues = CompliancePolicy::mode() === 'off'
            ? []
            : CompliancePolicy::sectionIssues($section->type, (array) $section->content);

        if ($section->type === 'carousel') {
            if (!$section->slider_id) {
                $issues[] = 'Není vybraný zdrojový Slider.';
            }
            elseif (empty($section->presentation['slides'])) {
                $issues[] = 'Slider nemá žádný kompletně použitelný publikovaný slide.';
            }
        }
        elseif ($section->type === 'accordion') {
            if (!$section->faq_group_id) {
                $issues[] = 'Není vybraná FAQ skupina.';
            }
            elseif (!$section->faq_group?->is_enabled || $section->faq_items->isEmpty()) {
                $issues[] = 'FAQ skupina nemá žádnou použitelnou publikovanou otázku s odpovědí.';
            }
        }
        elseif ($section->type === 'gallery') {
            if (!$section->gallery_id) {
                $issues[] = 'Není vybraná zdrojová galerie.';
            }
            elseif (!$section->gallery || $section->gallery->images->isEmpty()) {
                $issues[] = 'Vybraná galerie neobsahuje žádný obrázek.';
            }
        }
        elseif ($section->type === 'cards' && $section->items->isEmpty()) {
            $issues[] = 'Sekce neobsahuje žádnou kartu.';
        }

        $thumbnailSlot = SectionRegistry::instance()->wireframe($section->type)['thumbnail'];
        if ($thumbnailSlot && !$section->media->firstWhere('slot', $thumbnailSlot)) {
            $issues[] = 'Sekce nemá obrázek pro náhled ani pro výsledný obsah.';
        }

        $media = $section->media->concat($section->items->flatMap->media);
        foreach ($media as $use) {
            if (!$use->is_decorative && trim((string) $use->alt_text) === '') {
                $issues[] = 'Nedekorativní obrázek nemá vyplněný alternativní text.';
                break;
            }
        }

        return array_values(array_unique(array_filter($issues)));
    }
}
