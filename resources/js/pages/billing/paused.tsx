import { Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { logout } from '@/routes';

type Props = {
    business: string;
    suspended: boolean;
    can_manage: boolean;
};

/**
 * What staff, pickers and drivers see while their business's YardRunner
 * account is paused (trial ended, payment problem, or suspended).
 */
export default function Paused({ business, suspended, can_manage }: Props) {
    return (
        <>
            <Head title="Account paused" />
            <div className="space-y-4 text-center text-sm text-muted-foreground">
                <p>
                    {suspended
                        ? `${business}’s YardRunner account is suspended.`
                        : `${business}’s YardRunner subscription needs attention before you can carry on.`}{' '}
                    {suspended
                        ? can_manage
                            ? 'Contact YardRunner support to have it restored.'
                            : 'Ask the owner to contact YardRunner support.'
                        : 'Ask the owner or an admin to sign in and update the billing details. Everything is kept as it was.'}
                </p>
                <TextLink href={logout()} className="mx-auto block text-sm">
                    Log out
                </TextLink>
            </div>
        </>
    );
}

Paused.layout = {
    title: 'Account paused',
    description: 'YardRunner is unavailable for now.',
};
