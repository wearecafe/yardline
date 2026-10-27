export type PunchKind = 'in' | 'break_start' | 'break_end' | 'out';

export type ClockStateValue = 'out' | 'working' | 'on_break';

export type Punch = {
    id: number;
    type: PunchKind;
    label: string;
    time: string;
    /** "2026-10-26T07:02" in the yard's time zone, for inputs. */
    local: string;
    source: 'kiosk' | 'mobile' | 'driver' | 'manager';
    source_label: string;
    geofence: 'inside' | 'outside' | 'unknown' | 'take_home' | null;
    location: string | null;
    photo_url: string | null;
    adjusted: boolean;
    sent_late: boolean;
};

export type Adjustment = {
    id: number;
    action: 'add' | 'change' | 'remove' | 'hours';
    label: string;
    description: string;
    reason: string;
    by: string;
    at: string | null;
    disputed: boolean;
    open_dispute: boolean;
    dispute_note: string | null;
    resolved: boolean;
};

export type TimeException = {
    kind: string;
    label: string;
    detail: string;
};

export type TimeShift = {
    punches: Punch[];
    worked: number;
    worked_label: string;
    breaks: number;
    open: boolean;
    incomplete: boolean;
    auto_meal: boolean;
};

export type TimeDay = {
    date: string;
    label: string;
    weekday: string;
    is_today: boolean;
    shifts: TimeShift[];
    strays: Punch[];
    adjustments: Adjustment[];
    worked: number;
    worked_label: string;
    regular: number;
    overtime: number;
    overtime_label: string;
    breaks: number;
    exceptions: TimeException[];
    empty: boolean;
};

export type TimeTotals = {
    regular: number;
    overtime: number;
    breaks: number;
    total: number;
    regular_label: string;
    overtime_label: string;
    total_label: string;
    open: boolean;
};

export type PayPeriodInfo = {
    id: number;
    label: string;
    starts_on: string;
    ends_on: string;
    status: 'open' | 'locked' | 'exported';
    status_label: string;
    is_current: boolean;
    has_ended: boolean;
    prev_date: string;
    next_date: string | null;
};

export type TimesheetInfo = {
    status: 'open' | 'submitted' | 'approved';
    status_label: string;
    submitted_at: string | null;
    approved_at: string | null;
    approved_by?: string | null;
    blocker?: string | null;
};

export type ClockInfo = {
    state: ClockStateValue;
    state_label: string;
    allowed: { type: PunchKind; label: string }[];
    since: string | null;
    since_label: string | null;
    missed_out: string | null;
    week_minutes: number;
    week_label: string;
    overtime_after: number;
    wants_location: boolean;
};

export type SharedTimeStatus = {
    enabled: boolean;
    tracks: boolean;
    state: ClockStateValue | null;
    since: string | null;
} | null;
