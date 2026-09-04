import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AgreementActivationButton } from '@/pages/operations';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

describe('agreement activation', () => {
    it('shows an overlap refusal beside the activation control', () => {
        inertia.post.mockImplementation(
            (
                _href: string,
                _data: undefined,
                options: { onError: (errors: Record<string, string>) => void },
            ) => {
                options.onError({
                    engagement:
                        'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.',
                });
            },
        );

        render(<AgreementActivationButton href="/agreements/one/activate" />);
        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.',
        );
    });
});
