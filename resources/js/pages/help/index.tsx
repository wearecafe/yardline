import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import HelpLayout, {
    articleUrl,
    CategoryIcon,
    HelpSearch,
    SupportCard,
} from '@/components/help/help-layout';
import type {
    HelpArticleSummary,
    HelpSharedProps,
} from '@/components/help/help-layout';

type Category = {
    slug: string;
    title: string;
    summary: string;
    icon: string;
    count: number;
    top: HelpArticleSummary[];
};

type Props = HelpSharedProps & {
    categories: Category[];
    popular: HelpArticleSummary[];
};

export default function HelpIndex({
    categories,
    popular,
    nav,
    supportEmail,
}: Props) {
    return (
        <HelpLayout
            title="Help Center"
            description="Guides for running your supply yard, counter, deliveries and equipment rentals on YardRunner."
            nav={nav}
            supportEmail={supportEmail}
            showSearch={false}
        >
            <section className="border-b bg-secondary/40">
                <div className="mx-auto w-full max-w-3xl px-4 py-14 text-center sm:px-6 sm:py-20">
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                        How can we help?
                    </h1>
                    <p className="mt-3 text-muted-foreground">
                        Guides for the counter, the yard, the office and your
                        customers.
                    </p>
                    <div className="mx-auto mt-8 max-w-xl text-left">
                        <HelpSearch size="lg" />
                    </div>
                </div>
            </section>

            <section className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
                <h2 className="text-xl font-semibold tracking-tight">
                    Browse by topic
                </h2>
                <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {categories.map((category) => (
                        <div
                            key={category.slug}
                            className="flex flex-col rounded-xl border bg-card p-5 transition-colors hover:border-primary/50"
                        >
                            <Link
                                href={`/help/${category.slug}`}
                                className="group flex items-start gap-3"
                            >
                                <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent-soft text-primary">
                                    <CategoryIcon
                                        name={category.icon}
                                        className="size-5"
                                    />
                                </span>
                                <span>
                                    <span className="block font-semibold group-hover:text-primary">
                                        {category.title}
                                    </span>
                                    <span className="mt-1 block text-sm text-muted-foreground">
                                        {category.summary}
                                    </span>
                                </span>
                            </Link>
                            <ul className="mt-4 space-y-1.5 border-t pt-4 text-sm">
                                {category.top.map((article) => (
                                    <li key={article.slug}>
                                        <Link
                                            href={articleUrl(article)}
                                            className="hover:text-primary"
                                        >
                                            {article.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            <Link
                                href={`/help/${category.slug}`}
                                className="mt-auto inline-flex items-center gap-1 pt-4 text-sm font-medium text-primary"
                            >
                                All {category.count} articles
                                <ArrowRight className="size-3.5" aria-hidden />
                            </Link>
                        </div>
                    ))}
                </div>
            </section>

            <section className="mx-auto w-full max-w-6xl px-4 pb-14 sm:px-6">
                <h2 className="text-xl font-semibold tracking-tight">
                    Popular articles
                </h2>
                <ul className="mt-6 grid gap-px overflow-hidden rounded-xl border bg-border md:grid-cols-2">
                    {popular.map((article) => (
                        <li key={articleUrl(article)} className="bg-card">
                            <Link
                                href={articleUrl(article)}
                                className="group flex h-full items-center justify-between gap-3 px-5 py-4 hover:bg-secondary/50"
                            >
                                <span>
                                    <span className="block font-medium group-hover:text-primary">
                                        {article.title}
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        {article.summary}
                                    </span>
                                </span>
                                <ArrowRight
                                    className="size-4 shrink-0 text-primary"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            <section className="mx-auto w-full max-w-6xl px-4 pb-20 sm:px-6">
                <SupportCard email={supportEmail} />
            </section>
        </HelpLayout>
    );
}
