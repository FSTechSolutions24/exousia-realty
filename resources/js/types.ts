export interface Stage { id: number; name: string; name_ar: string | null; color: string; count?: number }
export interface Company { id: number; name: string; brand_color: string; role?: string }
export interface User { id: number; name: string; email: string; locale: 'en' | 'ar' }
export interface Bootstrap { user: User; company: Company; companies: Company[]; membership: { role: string; permissions: string[] }; stages: Stage[]; members: User[] }
export interface Lead {
  id: number; name: string; phone_original: string; phone_normalized: string; email?: string; source: string;
  intent: string; budget_min?: number; budget_max?: number; preferred_locations?: string[]; property_type?: string;
  bedrooms?: number; notes?: string; next_follow_up_at?: string; created_at: string; stage: Stage; assignee?: User | null;
}
