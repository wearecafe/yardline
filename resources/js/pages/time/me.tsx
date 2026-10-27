import { PageBody, PageHeader } from '@/components/yard/page-header';
import { MyTime } from '@/components/yard/my-time';
import type { MyTimeProps } from '@/components/yard/my-time';

/**
 * Everyone's own clock and timesheet in the yard app.
 */
export default function MyTimePage(props: MyTimeProps) {
    return (
        <PageBody className="max-w-2xl">
            <PageHeader
                eyebrow="Team"
                title="My time"
                description="Clock in and out, take breaks and check your hours before payroll."
            />
            <MyTime {...props} />
        </PageBody>
    );
}
