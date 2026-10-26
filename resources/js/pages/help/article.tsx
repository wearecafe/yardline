import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useState } from 'react';
import type { MouseEvent } from 'react';
import HelpLayout, {
    articleUrl,
    Crumbs,
    SupportCard,
} from '@/components/help/help-layout';
import type {
    HelpArticleSummary,
    HelpSharedProps,
} from '@/components/help/help-layout';
import { cn } from '@/lib/utils';

type Props = HelpSharedProps & {
    category: { slug: string; title: string; summary: string; icon: string };
    article: HelpArticleSummary & {
        html: string;
        toc: { id: string; title: string }[];
    };
    siblings: { slug: string; title: string }[];
    previous: HelpArticleSummary | null;
    next: HelpArticleSummary | null;
    related: HelpArticleSummary[];
};

/**
 * Links inside the Markdown body are plain anchors; send the ones that stay
 * in the help center through Inertia so the page doesn't fully reload.
 */
function followHelpLinks(event: MouseEvent<HTMLDivElement>) {
    const anchor = (event.target as HTMLElement).closest('a');

    if (
        !anchor ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.button !== 0
    ) {
        return;
    }

    const href = anchor.getAttribute('href') ?? '';

    if (href.startsWith('/help/')) {
        event.preventDefault();
        router.visit(href);
    }
}

function Feedback({
    supportEmail,
    title,
}: {
    supportEmail: string | null;
    title: string;
}) {
    const [answer, setAnswer] = useState<'yes' | 'no' | null>(null);

    if (answer === 'yes') {
        return (
            <p className="text-sm text-muted-foreground">
                Thanks for letting us know.
            </p>
        );
    }

    if (answer === 'no') {
        return (
            <p className="text-sm text-muted-foreground">
                Sorry it didn’t help.
                {supportEmail && (
                    <>
                        {' '}
                        <a
                            href={`mailto:${supportEmail}?subject=${encodeURIComponent(`Help article: ${title}`)}`}
                            className="font-medium text-primary hover:underline"
                        >
                            Tell us what was missing
                        </a>
                        .
                    </>
                )}
            </p>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
            Was this helpful?
            {(['yes', 'no'] as const).map((value) => (
                <button
                    key={value}
                    type="button"
                    onClick={() => setAnswer(value)}
                    className="rounded-md border bg-background px-3 py-1.5 font-medium text-foreground capitalize hover:border-primary/60"
                >
                    {value}
                </button>
            ))}
        </div>
    );
}

export default function HelpArticle({
    category,
    article,
    siblings,
    previous,
    next,
    related,
    nav,
    supportEmail,
}: Props) {
    return (
        <HelpLayout
            title={`${article.title} · Help`}
            description={article.summary}
            nav={nav}
            supportEmail={supportEmail}
        >
            <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-10 sm:px-6 lg:grid-cols-[220px_minmax(0,1fr)_200px]">
                <aside className="hidden lg:block">
                    <div className="sticky top-24">
                        <Link
                            href={`/help/${category.slug}`}
                            className="text-xs font-semibold tracking-wider text-muted-foreground uppercase hover:text-foreground"
                        >
                            {category.title}
                        </Link>
                        <nav className="mt-3 flex flex-col gap-0.5 text-sm">
                            {siblings.map((sibling) => (
                                <Link
                                    key={sibling.slug}
                                    href={`/help/${category.slug}/${sibling.slug}`}
                                    className={cn(
                                        'rounded-md px-2.5 py-1.5',
                                        sibling.slug === article.slug
                                            ? 'bg-accent-soft font-medium text-primary'
                                            : 'text-muted-foreground hover:bg-secondary hover:text-foreground',
                                    )}
                                    aria-current={
                                        sibling.slug === article.slug
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    {sibling.title}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </aside>

                <article className="min-w-0">
                    <Crumbs
                        items={[
                            { href: '/help', label: 'Help Center' },
                            {
                                href: `/help/${category.slug}`,
                                label: category.title,
                            },
                        ]}
                    />
                    <h1 className="mt-4 text-3xl font-bold tracking-tight">
                        {article.title}
                    </h1>
                    {article.summary && (
                        <p className="mt-2 text-lg text-muted-foreground">
                            {article.summary}
                        </p>
                    )}

                    <div
                        className="help-prose mt-8"
                        onClick={followHelpLinks}
                        dangerouslySetInnerHTML={{ __html: article.html }}
                    />

                    <div className="mt-12 border-t pt-6">
                        <Feedback
                            supportEmail={supportEmail}
                            title={article.title}
                        />
                    </div>

                    {(previous || next) && (
                        <div className="mt-8 grid gap-3 sm:grid-cols-2">
                            {previous ? (
                                <Link
                                    href={articleUrl(previous)}
                                    className="group rounded-lg border p-4 hover:border-primary/50"
                                >
                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                        <ArrowLeft
                                            className="size-3"
                                            aria-hidden
                                        />
                                        Previous
                                    </span>
                                    <span className="mt-1 block font-medium group-hover:text-primary">
                                        {previous.title}
                                    </span>
                                </Link>
                            ) : (
                                <span />
                            )}
                            {next && (
                                <Link
                                    href={articleUrl(next)}
                                    className="group rounded-lg border p-4 text-right hover:border-primary/50"
                                >
                                    <span className="flex items-center justify-end gap-1 text-xs text-muted-foreground">
                                        Next
                                        <ArrowRight
                                            className="size-3"
                                            aria-hidden
                                        />
                                    </span>
                                    <span className="mt-1 block font-medium group-hover:text-primary">
                                        {next.title}
                                    </span>
                                </Link>
                            )}
                        </div>
                    )}

                    {related.length > 0 && (
                        <div className="mt-10">
                            <h2 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                Related
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {related.map((item) => (
                                    <li key={articleUrl(item)}>
                                        <Link
                                            href={articleUrl(item)}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {item.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="mt-12">
                        <SupportCard email={supportEmail} />
                    </div>
                </article>

                <aside className="hidden lg:block">
                    {article.toc.length > 1 && (
                        <div className="sticky top-24">
                            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                On this page
                            </p>
                            <nav className="mt-3 flex flex-col gap-2 border-l text-sm">
                                {article.toc.map((heading) => (
                                    <a
                                        key={heading.id}
                                        href={`#${heading.id}`}
                                        className="-ml-px border-l border-transparent pl-3 text-muted-foreground hover:border-foreground hover:text-foreground"
                                    >
                                        {heading.title}
                                    </a>
                                ))}
                            </nav>
                        </div>
                    )}
                </aside>
            </div>
        </HelpLayout>
    );
}
