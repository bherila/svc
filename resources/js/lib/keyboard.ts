/**
 * Whether a keystroke is part of someone typing.
 *
 * A single-key shortcut ("/", "?") is only a shortcut when no text is being
 * entered: pressed inside a field it is a character, and a palette that stole
 * it would make it impossible to type a question mark into a note. The check
 * walks up from the event target because the editable thing is not always the
 * element that has focus - a rich-text editor focuses a child of the
 * `contenteditable` host - and recognises the ARIA text roles for widgets that
 * are not native inputs.
 *
 * Native `input`, `textarea` and `select` are all treated as typing, whatever
 * their type: a checkbox does not take text, but a shortcut firing on a focused
 * checkbox is still surprising, and the cost of the conservative answer is one
 * extra keypress.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    if (target.closest('input, textarea, select') !== null) {
        return true;
    }

    // `isContentEditable` is the resolved value (inheritance and
    // `contenteditable="plaintext-only"` included); the selector below covers
    // environments that do not compute it.
    if (target instanceof HTMLElement && target.isContentEditable) {
        return true;
    }

    return (
        target.closest(
            '[contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="searchbox"], [role="combobox"], [role="spinbutton"]',
        ) !== null
    );
}

/** Whether some other dialog is already open over the page. */
export function isDialogOpen(): boolean {
    return (
        document.querySelector('[role="dialog"], [role="alertdialog"]') !== null
    );
}
