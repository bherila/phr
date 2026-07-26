export type HealthLogData = {
id: number;
patient_id: number;
user_id: number;
created_by_user_id: number | null;
name: string;
kind: string;
description: string | null;
archived_at: string | null;
entries_count: number;
latest_entry_at: string | null;
created_at: string | null;
updated_at: string | null;
};
export type HealthLogEntryData = {
id: number;
health_log_id: number;
patient_id: number;
user_id: number;
recorded_by_user_id: number | null;
occurred_at: string;
title: string | null;
notes: string | null;
intensity: number | null;
tags: Array<string>;
details: Record<string, unknown> | null;
created_at: string | null;
updated_at: string | null;
};
