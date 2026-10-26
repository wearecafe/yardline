import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import HelpLayout, {
    articleUrl,
    CategoryIcon,
    Crumbs,
    SupportCard,
} from '@/components/help/help-layout';
import type {
    HelpArticleSummary,
    HelpSharedProps,
} from '@/components/help/help-layout';

type Props = HelpSharedProps & {
    category: { slug: string; title: string; summary: string; icon: string };
    articles: HelpArticleSummary[];
};

export default function HelpCategory({
    category,
    articles,
    nav,
    supportEmail,
}: Props) {
    return (
        <HelpLayout
            title={`${category.title} · Help`}
            description={category.summary}
            nav={nav}
            supportEmail={supportEmail}
        >
            <div className="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
                <Crumbs
                    items={[
                        { href: '/help', label: 'Help Center' },
                        { label: category.title },
                    ]}
                />
                <div className="mt-6 flex items-start gap-4">
                    <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-accent-soft text-primary">
                        <CategoryIcon name={category.icon} className="size-6" />
                    </span>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                            {category.title}
                        </h1>
                        <p className="mt-1 text-muted-foreground">
                            {category.summary}
                        </p>
                    </div>
                </div>

                <ul className="mt-8 divide-y rounded-xl border bg-card">
                    {articles.map((article) => (
                        <li key={article.slug}>
                            <Link
                                href={articleUrl(article)}
                                className="group flex items-center justify-between gap-4 px-5 py-4 hover:bg-secondary/50"
                            >
                                <span>
                                    <span className="block font-medium group-hover:text-primary">
                                        {article.title}
                                    </span>
                                    <span className="mt-0.5 block text-sm text-muted-foreground">
                                        {article.summary}
                                    </span>
                                </span>
                                <ArrowRight
                                    className="size-4 shrink-0 text-muted-foreground group-hover:text-primary"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    ))}
                </ul>

                <div className="mt-12">
                    <SupportCard email={supportEmail} />
                </div>
            </div>
        </HelpLayout>
    );
}
