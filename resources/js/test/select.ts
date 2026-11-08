import { screen } from '@testing-library/react';
import type userEvent from '@testing-library/user-event';

/**
 * Open the shared `Select` named `name` and choose the option labelled
 * `option`, the way a reader does: a click on the trigger, a click on the item.
 * jsdom has no native `<select>` to drive with `selectOptions`.
 */
export async function chooseOption(
    user: ReturnType<typeof userEvent.setup>,
    name: string | RegExp,
    option: string | RegExp,
) {
    await user.click(screen.getByRole('combobox', { name }));
    await user.click(await screen.findByRole('option', { name: option }));
}
