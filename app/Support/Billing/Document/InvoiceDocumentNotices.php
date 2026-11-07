<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceStatus;

/**
 * The words and yes-or-no decisions the document makes on top of stored
 * figures: which banner an audience reads, which notices print, whether a pay
 * link is offered. Pure and database-free, so each decision is unit-tested.
 */
final class InvoiceDocumentNotices
{
    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public static function audienceBanner(string $audience): string
    {
        return $audience === InvoiceLineDetail::OPERATOR ? 'Administrator copy: includes internal descriptions' : '';
    }

    public static function statementIntro(bool $isCorrection): string
    {
        return $isCorrection
            ? 'For the work this correction reconciles and the pool it draws on. All figures are hours.'
            : 'For the work this invoice reconciles and the retainer period that follows it. All figures are hours.';
    }

    /** The sentence under Appendix B's heading. */
    public static function receiptsIntro(string $audience): string
    {
        return match ($audience) {
            InvoiceLineDetail::OPERATOR => 'The expenses billed on this invoice, with the receipt filed for each. An expense with no receipt on file is listed without one.',
            InvoiceLineDetail::CLIENT => 'The expenses billed on this invoice that have a receipt shared with you, with that receipt.',
        };
    }

    public static function reissueNotice(?string $reissuedOn): ?string
    {
        return $reissuedOn === null
            ? null
            : 'Reissued '.$reissuedOn.' under the same invoice number; this replaces the earlier version.';
    }

    public static function voidNotice(?string $voidedOn, string $status): ?string
    {
        if ($status !== InvoiceStatus::Void->value) {
            return null;
        }

        return $voidedOn === null
            ? 'This invoice was voided. Nothing is owed on it.'
            : 'This invoice was voided on '.$voidedOn.'. Nothing is owed on it.';
    }

    /**
     * Whether a client copy has to explain "Professional services": only when an
     * entry actually printed it.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @param  array<string, list<array{description: string}>>  $detail
     */
    public static function genericLabelUsed(string $audience, array $detail): bool
    {
        if ($audience !== InvoiceLineDetail::CLIENT) {
            return false;
        }
        foreach ($detail as $items) {
            foreach ($items as $item) {
                if ($item['description'] === InvoiceLineDetail::CLIENT_GENERIC_LABEL) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A pay-online line is printed only on a real invoice a client can see and
     * still owes money on. A proforma, a draft, a void or a paid invoice has no
     * place to pay.
     */
    public static function offersPayLink(DocumentMode $mode, string $status, int $balance, bool $visibleToClient): bool
    {
        return match ($mode) {
            DocumentMode::Invoice => $visibleToClient && $balance > 0 && in_array($status, InvoiceStatus::collectible(), true),
            DocumentMode::Proforma => false,
        };
    }

    /**
     * The appendix, in line order, for lines with work behind them.
     *
     * @param  list<InvoiceDocumentLine>  $lines
     * @param  array<string, list<array{worked_on: string, project: string|null, description: string, minutes: int}>>  $detail
     * @return list<array{line_id: string, description: string, minutes: int, entries: list<array{worked_on: string, project: string|null, description: string, minutes: int}>}>
     */
    public static function appendix(array $lines, array $detail): array
    {
        $blocks = [];
        foreach ($lines as $line) {
            $items = $detail[$line->id] ?? [];
            if ($items === []) {
                continue;
            }
            $blocks[] = [
                'line_id' => $line->id,
                'description' => $line->description,
                'minutes' => array_sum(array_column($items, 'minutes')),
                'entries' => $items,
            ];
        }

        return $blocks;
    }
}
