<?php

namespace App\Services\Billing\Pdf;

use App\Support\Billing\Document\InvoiceDocument;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use RuntimeException;

/**
 * The Dompdf implementation of {@see InvoicePdfRenderer}.
 *
 * The template (`invoices.document`) is limited to what Dompdf lays out: tables,
 * inline-block and fixed positioning, no flexbox or grid. The paper is always
 * white. Inter is shipped as TTF under `resources/fonts/inter` and registered
 * once into Dompdf's font cache; DejaVu Sans, which Dompdf bundles, is the
 * fallback for any glyph Inter lacks.
 */
final class DompdfInvoicePdfRenderer implements InvoicePdfRenderer
{
    /**
     * US Letter, 8.5 x 11 in, in PDF points (72 to the inch).
     *
     * Given as a box rather than the name `letter`, so the page size is a fact
     * this class states and a test can read back from the MediaBox, rather than
     * whatever the renderer's paper table maps that name to.
     *
     * @var array{0: float, 1: float, 2: float, 3: float}
     */
    public const US_LETTER_POINTS = [0.0, 0.0, 612.0, 792.0];

    private const string FONT_FAMILY = 'Inter';

    public function render(InvoiceDocument $document): RenderedInvoicePdf
    {
        $dompdf = $this->laidOut($document);

        return new RenderedInvoicePdf((string) $dompdf->output(), $dompdf->getCanvas()->get_page_count());
    }

    /** The document as HTML, exactly what is laid out. */
    public function html(InvoiceDocument $document): View
    {
        return view('invoices.document', ['document' => $document]);
    }

    /**
     * The laid-out document before it is serialised.
     *
     * `$onFrame` sees each box as the renderer paints it, with its position -
     * the only way to show that nothing runs past the page edge, which the PDF
     * bytes (compressed) and the HTML (not laid out) cannot.
     *
     * @param  (callable(Frame): void)|null  $onFrame
     */
    public function laidOut(InvoiceDocument $document, ?callable $onFrame = null): Dompdf
    {
        $dompdf = new Dompdf($this->options($this->preparedFontDirectory()));
        if ($onFrame !== null) {
            $dompdf->setCallbacks([['event' => 'end_frame', 'f' => static function (Frame $frame) use ($onFrame): void {
                $onFrame($frame);
            }]]);
        }
        $dompdf->loadHtml($this->html($document)->render());
        $dompdf->setPaper(self::US_LETTER_POINTS);
        $dompdf->render();
        $this->numberPages($dompdf);

        return $dompdf;
    }

    private function options(string $directory): Options
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('fontDir', $directory);
        $options->set('fontCache', $directory);
        $options->set('defaultFont', 'DejaVu Sans');
        // Dompdf reads a font only from inside its chroot; the shipped TTFs are
        // the one place outside its own directory that it may read.
        $options->set('chroot', [resource_path('fonts'), $directory]);

        return $options;
    }

    /**
     * Where Dompdf keeps its font metrics: writable, shared by every request
     * and every worker, and written exactly once.
     *
     * Registering a font writes the metrics files and rewrites the family
     * index, and Dompdf writes more cache files the first time it lays a font
     * out. Left to each render, two workers starting together read each other's
     * half-written files and one render fails. So the first caller registers
     * Inter and warms every cache under an exclusive lock, and publishes a
     * marker once the directory is complete; after that nothing writes to it,
     * and a reader can never meet a partial file.
     */
    private function preparedFontDirectory(): string
    {
        $directory = storage_path('framework/dompdf-fonts');
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('The invoice font directory could not be created.');
        }
        $marker = $directory.'/inter-1.ready';
        if (is_file($marker)) {
            return $directory;
        }

        $lock = fopen($directory.'/.lock', 'c');
        try {
            if ($lock !== false) {
                flock($lock, LOCK_EX);
            }
            clearstatcache(true, $marker);
            if (! is_file($marker)) {
                $this->registerFonts($directory);
                $pending = $marker.'.'.getmypid();
                file_put_contents($pending, 'ready');
                rename($pending, $marker);
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $directory;
    }

    /** Register Inter and lay out a line in each face, so every cache file exists. */
    private function registerFonts(string $directory): void
    {
        $dompdf = new Dompdf($this->options($directory));
        $metrics = $dompdf->getFontMetrics();
        $fonts = resource_path('fonts/inter');
        foreach ([['normal', 'Inter-Regular.ttf'], ['bold', 'Inter-Bold.ttf']] as [$weight, $file]) {
            $metrics->registerFont(['family' => self::FONT_FAMILY, 'style' => 'normal', 'weight' => $weight], $fonts.'/'.$file);
        }
        $metrics->saveFontFamilies();
        $dompdf->loadHtml('<p style="font-family: Inter, DejaVu Sans">Inter <b>bold</b></p><p style="font-family: DejaVu Sans">DejaVu <b>bold</b></p>');
        $dompdf->setPaper(self::US_LETTER_POINTS);
        $dompdf->render();
    }

    /**
     * "Page N of M" in the running footer of every page.
     *
     * Written onto the canvas after layout rather than through CSS: the total
     * is only known once every page exists, and the renderer's `counter(pages)`
     * prints zero. Right-aligned to the same margin as the footer rule.
     */
    private function numberPages(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont(self::FONT_FAMILY) ?? $metrics->getFont('DejaVu Sans');
        // Bundled or shipped, so always found; a document is still worth more
        // unnumbered than not at all.
        if ($font === null) {
            return;
        }
        $size = 6.75;
        // Measured with two-digit numbers, the widest a realistic document
        // reaches, so the text ends at the margin once the numbers are filled in.
        $width = $metrics->getTextWidth('Page 88 of 88', $font, $size);
        $rightMargin = 0.75 * 72;
        $canvas->page_text(
            $canvas->get_width() - $rightMargin - $width,
            $canvas->get_height() - 0.55 * 72 - 2,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            $size,
            [0.42, 0.45, 0.5],
        );
    }
}
