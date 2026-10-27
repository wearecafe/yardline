import { Activity, BarChart3, Building2, LayoutDashboard } from 'lucide-react';
import IntegrationHealthController from '@/actions/App/Http/Controllers/Admin/IntegrationHealthController';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import type { NavSection } from '@/lib/navigation';

/**
 * The back office sidebar, for YardRunner staff.
 */
export const adminNavSections: NavSection[] = [
    {
        label: 'Back office',
        items: [
            {
                title: 'Overview',
                href: OverviewController.url(),
                icon: LayoutDashboard,
            },
            {
                title: 'Tenants',
                href: TenantController.index(),
                icon: Building2,
            },
            {
                title: 'Activity reports',
                href: ReportController.url(),
                icon: BarChart3,
            },
            {
                title: 'Integration health',
                href: IntegrationHealthController.index(),
                icon: Activity,
            },
        ],
    },
];
