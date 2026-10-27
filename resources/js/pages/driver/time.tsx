import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import { MyTime } from '@/components/yard/my-time';
import type { MyTimeProps } from '@/components/yard/my-time';

/**
 * The driver's clock and hours, inside the driver app.
 */
export default function DriverTime(props: MyTimeProps) {
    return (
        <>
            <div className="mb-4 flex items-center gap-2">
                <Link
                    href={DriverController.today()}
                    className="flex items-center gap-1 rounded-lg p-2 text-sm text-muted-foreground hover:bg-secondary"
                >
                    <ChevronLeft className="size-4" /> My run
                </Link>
                <h1 className="text-xl font-bold">My time</h1>
            </div>
            <MyTime {...props} />
        </>
    );
}
