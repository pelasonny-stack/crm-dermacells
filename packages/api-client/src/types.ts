/**
 * Shared domain types for CRM Dermacells.
 * Manually maintained for Phase 15; will be replaced by openapi-typescript
 * codegen from Scribe output (POST Phase 1 OpenAPI spec stabilization).
 */

export type UserRole = 'director' | 'distributor' | 'seller';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  can_sell: boolean;
}

export type CustomerCategory = 'A' | 'B' | 'C' | 'D';

export interface Customer {
  id: number;
  name: string;
  last_name: string;
  cuit: string;
  phone: string;
  email: string;
  address: string;
  category: CustomerCategory;
  zone_id: number | null;
  payment_term: string | null;
  reference_price_usd: number | null;
  reference_price_unit_usd: number | null;
  assigned_seller_id: number | null;
  created_at: string;
  updated_at: string;
}

export type SaleStatus = 'draft' | 'confirmed' | 'delivered' | 'cancelled';
export type Currency = 'ARS' | 'USD';

export interface SaleItem {
  id: number;
  product: string;
  boxes: number;
  loose_units: number;
  unit_price_usd: number;
  subtotal: number;
  currency: Currency;
}

export interface Sale {
  id: number;
  customer_id: number;
  seller_id: number;
  status: SaleStatus;
  currency: Currency;
  exchange_rate: number | null;
  total: number | null;
  notes: string | null;
  items: SaleItem[];
  delegated_delivery: boolean;
  created_at: string;
  updated_at: string;
}

export type PaymentMethod =
  | 'transfer_dermacells'
  | 'transfer_distributor'
  | 'cash'
  | 'credit_card'
  | 'check';

export interface Payment {
  id: number;
  sale_id: number;
  customer_id: number;
  method: PaymentMethod;
  amount: number;
  currency: Currency;
  is_advance: boolean;
  due_date: string | null;
  paid_at: string | null;
  created_at: string;
}

export interface Stock {
  product: string;
  boxes: number;
  loose_units: number;
  reserved_boxes: number;
  available_boxes: number;
  minimum: number;
}

export interface DashboardResponse {
  role: UserRole;
  today: Record<string, unknown>;
  month: Record<string, unknown>;
  operation: Record<string, unknown>;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    total: number;
    current_page: number;
    last_page: number;
    per_page: number;
  };
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
}

/** RFC 7807 problem+json error shape */
export interface ProblemDetail {
  type: string;
  title: string;
  status: number;
  detail?: string;
  code?: string;
  meta?: Record<string, unknown>;
}
