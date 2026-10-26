import { Link } from '@inertiajs/react';
import HelpLayout, {
    articleUrl,
    Crumbs,
    HelpSearch,
    SupportCard,
} from '@/components/help/help-layout';
import type {
    HelpArticleSummary,
    HelpSharedProps,
} from '@/components/help/help-layout';

type Result = HelpArticleSummary & { categoryTitle: string; snippet: string };

type Props = HelpSharedProps & {
    query: string;
    results: Result[];
};

export default function HelpSearchPage({
    query,
    results,
    nav,
    supportEmail,
}: Props) {
    return (
        <HelpLayout
            title={query ? `“${query}” · Help` : 'Search · Help'}
            nav={nav}
            supportEmail={supportEmail}
            showSearch={false}
        >
            <div className="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
                <Crumbs
                    items={[
                        { href: '/help', label: 'Help Center' },
                        { label: 'Search' },
                    ]}
                />
                <div className="mt-6">
                    <HelpSearch key={query} defaultValue={query} size="lg" />
                </div>

                {query !== '' && (
                    <p className="mt-6 text-sm text-muted-foreground">
                        {results.length === 0
                            ? `No articles match “${query}”. Try fewer or different words.`
                            : `${results.length} ${results.length === 1 ? 'article' : 'articles'} for “${query}”`}
                    </p>
                )}

                {results.length > 0 && (
                    <ul className="mt-4 divide-y rounded-xl border bg-card">
                        {results.map((result) => (
                            <li key={articleUrl(result)}>
                                <Link
                                    href={articleUrl(result)}
                                    className="group block px-5 py-4 hover:bg-secondary/50"
                                >
                                    <span className="text-xs text-muted-foreground">
                                        {result.categoryTitle}
                                    </span>
                                    <span className="mt-0.5 block font-medium group-hover:text-primary">
                                        {result.title}
                                    </span>
                                    <span className="mt-1 block text-sm text-muted-foreground">
                                        {result.snippet}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="mt-12">
                    <SupportCard email={supportEmail} />
                </div>
            </div>
        </HelpLayout>
    );
}
