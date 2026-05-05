import { api } from './client';

export type PosoEntity = {
  id: string;
  name: string;
  legal_form: string | null;
  relation_type: string;
  tenant_id: string;
  tenant_name: string | null;
  tenant_slug: string | null;
};

export type PosoBootstrap = {
  app: 'poso';
  tier: string;
  main_tier: string;
  accounting_tier: string;
  entities: PosoEntity[];
  active_entity: PosoEntity | null;
  tenant: {
    id: string | null;
    slug: string | null;
    name: string | null;
  };
  user: {
    id: string | null;
    name: string;
    role: string;
  };
};

export function getBootstrap() {
  return api<{ data: PosoBootstrap }>('/api/v1/me').then((response) => response.data);
}

export function selectEntity(entityId: string) {
  return api<{ data: { active_entity: PosoEntity } }>('/api/v1/context/entity', {
    method: 'POST',
    body: JSON.stringify({ entity_id: entityId })
  }).then((response) => response.data.active_entity);
}

