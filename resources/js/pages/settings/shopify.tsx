import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ShopifyController from '@/actions/App/Http/Controllers/Settings/ShopifyController';
import Heading from '@/components/heading';
import { Field } from '@/components/yard/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';

type Props = {
    integration: {
        status: 'connected' | 'paused' | 'error';
        external_account: string;
        last_synced_at: string | null;
        last_error: string | null;
    } | null;
    webhookUrl: string;
};

export default function Shopify({ integration, webhookUrl }: Props) {
    const form = useForm({
        shop_domain: integration?.external_account ?? '',
        access_token: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(ShopifyController.update.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('access_token'),
        });
    }

    return (
        <>
            <Head title="Shopify" />
            <h1 className="sr-only">Shopify settings</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Shopify"
                    description="Yardline sets the price. Products and customers are copied to your Shopify store when they change here."
                />

                {integration && (
                    <div className="rounded-md border border-border bg-card p-4 text-sm">
                        <div className="font-medium">
                            Connected to {integration.external_account}
                        </div>
                        <div className="text-muted-foreground">
                            {integration.last_synced_at
                                ? `Last sync ${formatDate(integration.last_synced_at)}.`
                                : 'Nothing synced yet. Edit a product to send it.'}
                        </div>
                        {integration.last_error && (
                            <p className="mt-2 text-danger">
                                Last sync failed: {integration.last_error}
                            </p>
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-3"
                            onClick={() => {
                                if (
                                    confirm(
                                        'Disconnect Shopify? Nothing in either system is deleted.',
                                    )
                                ) {
                                    router.delete(
                                        ShopifyController.destroy.url(),
                                    );
                                }
                            }}
                        >
                            Disconnect
                        </Button>
                    </div>
                )}

                <form onSubmit={submit} className="grid gap-6">
                    <Field
                        label="Store domain"
                        htmlFor="shop_domain"
                        error={form.errors.shop_domain}
                    >
                        <Input
                            id="shop_domain"
                            value={form.data.shop_domain}
                            placeholder="your-yard.myshopify.com"
                            onChange={(e) =>
                                form.setData('shop_domain', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="Admin API access token"
                        htmlFor="access_token"
                        error={form.errors.access_token}
                        hint="From a custom app in Shopify admin (Settings, Apps and sales channels, Develop apps). It needs read and write access to products, inventory and customers."
                    >
                        <Input
                            id="access_token"
                            type="password"
                            autoComplete="off"
                            value={form.data.access_token}
                            onChange={(e) =>
                                form.setData('access_token', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {integration
                                ? 'Update connection'
                                : 'Connect Shopify'}
                        </Button>
                    </div>
                </form>

                <div className="text-sm">
                    <div className="font-medium">Order webhooks</div>
                    <p className="text-muted-foreground">
                        In the Shopify app, subscribe to orders/create and
                        orders/paid at:
                    </p>
                    <code className="mt-1 block overflow-x-auto rounded bg-muted px-2 py-1 text-xs">
                        {webhookUrl}
                    </code>
                </div>
            </div>
        </>
    );
}

Shopify.layout = {
    breadcrumbs: [{ title: 'Shopify', href: ShopifyController.edit() }],
};
