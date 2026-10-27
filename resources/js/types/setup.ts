export type SetupStepKey =
    | 'business'
    | 'yards'
    | 'work'
    | 'products'
    | 'team'
    | 'brand'
    | 'payments'
    | 'done';

export type SetupStepStatus = 'done' | 'skipped' | 'todo';

/** Where a business is in Setup, on every Setup page. */
export type SetupState = {
    step: SetupStepKey;
    title: string;
    required: boolean;
    previous: SetupStepKey | null;
    next: SetupStepKey;
    steps: {
        key: Exclude<SetupStepKey, 'done'>;
        title: string;
        required: boolean;
        status: SetupStepStatus;
        open: boolean;
    }[];
    basics_done: boolean;
};

export type ChecklistItem = {
    key: string;
    title: string;
    detail: string;
    href: string;
    done: boolean;
};

/** The "Finish setting up" card on Today. */
export type SetupChecklist = {
    items: ChecklistItem[];
    done: number;
    total: number;
};
