type Line = { qty: string; name: string };

export function OrderLineList({
    lines,
    more = 0,
}: {
    lines: Line[];
    more?: number;
}) {
    if (lines.length === 0) {
        return <span className="text-muted-foreground">No items</span>;
    }

    return (
        <ul className="flex flex-col gap-1">
            {lines.map((l, i) => (
                <li
                    key={i}
                    className="grid grid-cols-[4.5rem_minmax(0,1fr)] items-baseline gap-2"
                >
                    <span className="figure text-right whitespace-nowrap text-foreground">
                        {l.qty}
                    </span>
                    <span className="text-subtle">{l.name}</span>
                </li>
            ))}
            {more > 0 && (
                <li className="pl-[5rem] text-xs text-muted-foreground">
                    + {more} more
                </li>
            )}
        </ul>
    );
}
