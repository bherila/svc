import { ApiCredentialsSection } from 'bwh-auth';
import type {
    ApiCredentialRequest,
    AuthButtonComponentProps,
    AuthComponentInput,
    AuthContainerComponentProps,
} from 'bwh-auth';
import { Badge } from '@/components/ui/badge';
import { Button as BaseButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest } from '@/lib/api';
import { cn } from '@/lib/utils';

/** The sensitive-group warning, in the warning colours the rest of the app uses. */
function Alert({ className, ...props }: AuthContainerComponentProps) {
    return (
        <div
            role="note"
            className={cn(
                'rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm wrap-anywhere text-amber-800 dark:text-amber-300',
                className,
            )}
            {...props}
        />
    );
}

/** Presets and the Admin disclosure carry long labels: let the button grow rather than spill over its neighbours. */
function Button({ className, ...props }: AuthButtonComponentProps) {
    return (
        <BaseButton
            className={cn(
                'h-auto min-h-8 py-1.5 text-left whitespace-normal',
                className,
            )}
            {...props}
        />
    );
}

const components = {
    Alert,
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    Input,
    Label,
    Textarea,
} satisfies AuthComponentInput;

/**
 * Credential requests go through SVC's own client, so they share its session
 * expiry wording and CSRF handling. The section reads the JSON `data` envelope.
 */
const request: ApiCredentialRequest = async (method, url, body) => {
    const result = await apiRequest(method, url, body ?? {});

    return result.ok
        ? { ok: true, status: result.status, payload: { data: result.data } }
        : { ok: false, status: result.status, message: result.message };
};

export type RestLinks = {
    api_base_url: string;
    openapi_url: string;
    authorize_url: string;
    token_url: string;
    /** The credential service's JSON index: scopes, groups, presets, lists and action URLs. */
    index_url: string;
};

/**
 * Credentials for apps that use the REST API instead of MCP (#384, #582).
 *
 * The auth package's section owns the forms, the grouped scope picker and the
 * show-once secrets. This page supplies its look (shadcn components), its
 * requests, and the connector URLs a developer needs.
 */
export function RestCredentials({ rest }: { rest: RestLinks }) {
    return (
        <section className="grid min-w-0 grid-cols-1 gap-3">
            <h2 className="text-lg font-semibold">
                Other apps: REST API with OAuth or an API token
            </h2>
            <p className="text-sm text-muted-foreground">
                Apps that do not speak MCP can use the same operations through
                the REST API. Point them at the OpenAPI document, then either
                register an OAuth app (for apps that sign you in) or create an
                API token (for apps that ask for a key).
            </p>
            {/* The section's markup does not break unbroken runs (long scope ids,
                group labels and descriptions): overflow-wrap is inherited, and
                min-w-0 lets its flex and grid children shrink to it. */}
            <div className="min-w-0 wrap-anywhere [&_*]:min-w-0">
                <ApiCredentialsSection
                    indexUrl={rest.index_url}
                    components={components}
                    request={request}
                    links={[
                        { label: 'OpenAPI document', url: rest.openapi_url },
                        { label: 'API base URL', url: rest.api_base_url },
                        {
                            label: 'OAuth authorize URL',
                            url: rest.authorize_url,
                        },
                        { label: 'OAuth token URL', url: rest.token_url },
                    ]}
                />
            </div>
        </section>
    );
}
