import { Link } from '@inertiajs/react';
import HelpLayout, {
    Crumbs,
    HelpSearch,
    SupportCard,
} from '@/components/help/help-layout';
import type { HelpSharedProps } from '@/components/help/help-layout';
import { Marked, resultUrl } from '@/components/help/help-search';
import type { HelpSearchResult } from '@/components/help/help-search';

type Props = HelpSharedProps & {
    query: string;
    results: HelpSearchResult[];
};

function ResultList({ results }: { results: HelpSearchResult[] }) {
    return (
        <ul className="mt-4 divide-y rounded-xl border bg-card">
            {results.map((result) => (
                <li key={resultUrl(result)}>
                    <Link
                        href={resultUrl(result)}
                        className="group block px-5 py-4 hover:bg-secondary/50"
                    >
                        <span className="text-xs text-muted-foreground">
                            {result.categoryTitle}
                            {result.section && ` › ${result.section.title}`}
                        </span>
                        <span className="mt-0.5 block font-medium group-hover:text-primary">
                            <Marked parts={result.titleParts} />
                        </span>
                        <span className="mt-1 block text-sm text-muted-foreground">
                            <Marked parts={result.snippetParts} />
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

export default function HelpSearchPage({
    query,
    results,
    nav,
    supportEmail,
}: Props) {
    const complete = results.filter((result) => result.complete);
    const partial = results.filter((result) => !result.complete);

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
                    <HelpSearch
                        key={query}
                        defaultValue={query}
                        size="lg"
                        autoFocus={query === ''}
                    />
                </div>

                {query !== '' && (
                    <p className="mt-6 text-sm text-muted-foreground">
                        {results.length === 0
                            ? `No articles match “${query}”. Try fewer or different words.`
                            : complete.length === 0
                              ? `No articles have all of “${query}”. These have most of it.`
                              : `${complete.length} ${complete.length === 1 ? 'article' : 'articles'} for “${query}”`}
                    </p>
                )}

                {complete.length > 0 && <ResultList results={complete} />}

                {complete.length > 0 && partial.length > 0 && (
                    <h2 className="mt-8 text-sm font-medium text-muted-foreground">
                        Close matches
                    </h2>
                )}

                {partial.length > 0 && <ResultList results={partial} />}

                <div className="mt-12">
                    <SupportCard email={supportEmail} />
                </div>
            </div>
        </HelpLayout>
    );
}
