/**
 * The one column every authenticated screen is drawn in.
 *
 * Defined once because the navbar and the page beneath it have to agree. They
 * did not: the bar spanned the viewport while each page centred its own column
 * of whatever width it had picked - `max-w-4xl` here, `max-w-6xl` there - so on
 * a wide screen the wordmark sat at the far left edge, the account menu at the
 * far right, and the content somewhere in between, lining up with neither. The
 * result reads as a margin bug, because it is one.
 *
 * The header still paints its border and background edge to edge; only its
 * contents are brought into this column, so the bar and the page share a
 * gutter. Pages that want a narrower measure for reading - a proposal, the
 * workspace selector - constrain their own content *inside* this rather than
 * replacing it, so the outer gutter never moves.
 */
export const SHELL_CONTAINER = 'mx-auto w-full max-w-6xl px-4 sm:px-6';
