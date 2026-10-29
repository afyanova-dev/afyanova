import { Button, ButtonSet, Search, Tag, TreeView } from '@carbon/react';
import { Building, Floorplan, Hospital, Location } from '@/icons';
import { useMemo, useState, type ReactNode } from 'react';
import { EmptyState } from '@/components/ui';
import type { Department, Room, Unit } from './Facility';

export type StructureKind = 'department' | 'unit' | 'room';

interface FacilityWorkspaceProps {
  departments: Department[];
  units: Unit[];
  rooms: Room[];
  canManageStructure: boolean;
  expandedLocationId?: string | null;
  onAdd: (mode: StructureKind, parentId?: string) => void;
  renderActions: (kind: StructureKind, id: string) => ReactNode;
}

function formatLocationType(value: string): string {
  return value.replaceAll('_', ' ').toLocaleLowerCase().replace(/\b[a-z]/g, (letter) => letter.toLocaleUpperCase());
}

function matches(query: string, ...values: (string | null)[]): boolean {
  if (!query) return true;
  return values.some((value) => value?.toLocaleLowerCase().includes(query));
}

function TreeLabel({
  name,
  detail,
  status,
  actions,
}: {
  name: string;
  detail?: string;
  status?: string;
  actions?: ReactNode;
}): ReactNode {
  return (
    <span className="admin-organization__tree-label">
      <strong>{name}</strong>
      {detail ? <small>{detail}</small> : null}
      {status ? (
        <Tag size="sm" type={status === 'ACTIVE' ? 'green' : 'gray'}>
          {formatLocationType(status)}
        </Tag>
      ) : null}
      {actions ? (
        <span
          className="admin-organization__tree-actions"
          onClick={(event) => event.stopPropagation()}
          onKeyDown={(event) => event.stopPropagation()}
        >
          {actions}
        </span>
      ) : null}
    </span>
  );
}

export function FacilityWorkspace({
  departments,
  units,
  rooms,
  canManageStructure,
  expandedLocationId = null,
  onAdd,
  renderActions,
}: FacilityWorkspaceProps): ReactNode {
  const [search, setSearch] = useState('');
  const query = search.trim().toLocaleLowerCase();
  const directRooms = rooms.filter((room) => room.unitId === null);
  const visibleDirectRooms = directRooms.filter((room) => (
    matches(query, room.name, room.code)
  ));

  const visibleDepartments = useMemo(() => departments.filter((department) => {
    const departmentUnits = units.filter((unit) => unit.departmentId === department.id);
    return matches(query, department.name, department.code)
      || departmentUnits.some((unit) => matches(query, unit.name, unit.code)
        || rooms.some((room) => room.unitId === unit.id
          && matches(query, room.name, room.code)));
  }), [departments, query, rooms, units]);

  function renderRoom(room: Room): ReactNode {
    return (
      <TreeView.TreeNode
        key={room.id}
        id={`room-${room.id}`}
        renderIcon={Floorplan}
        label={(
          <TreeLabel
            name={room.name}
            detail={`${room.code} · ${formatLocationType(room.type)}`}
            status={room.status}
            actions={canManageStructure ? (
              <ButtonSet className="admin-organization__tree-button-set">
                {renderActions('room', room.id)}
              </ButtonSet>
            ) : undefined}
          />
        )}
      />
    );
  }

  const tree = (
    <TreeView label="Facility locations" hideLabel className="admin-organization__location-tree">
      {visibleDepartments.map((department) => {
        const departmentMatches = matches(query, department.name, department.code);
        const departmentUnits = units.filter((unit) => unit.departmentId === department.id).filter((unit) => (
          !query
          || departmentMatches
          || matches(query, unit.name, unit.code)
          || rooms.some((room) => room.unitId === unit.id
            && matches(query, room.name, room.code))
        ));

        return (
          <TreeView.TreeNode
            key={department.id}
            id={`department-${department.id}`}
            isExpanded={query !== ''}
            renderIcon={Building}
            label={(
              <TreeLabel
                name={department.name}
                detail={`${department.code}${department.kind ? ` · ${formatLocationType(department.kind)}` : ''}`}
                status={department.status}
                actions={canManageStructure ? (
                  <ButtonSet className="admin-organization__tree-button-set">
                    <Button
                      kind="ghost"
                      size="sm"
                      aria-label={`Add unit to ${department.name}`}
                      onClick={() => onAdd('unit', department.id)}
                    >
                      Add unit
                    </Button>
                    {renderActions('department', department.id)}
                  </ButtonSet>
                ) : undefined}
              />
            )}
          >
            {departmentUnits.map((unit) => {
              const unitMatches = departmentMatches || matches(query, unit.name, unit.code);
              const unitRooms = rooms.filter((room) => (
                room.unitId === unit.id
                && (!query || unitMatches || matches(query, room.name, room.code))
              ));

              return (
                <TreeView.TreeNode
                  key={unit.id}
                  id={`unit-${unit.id}`}
                  isExpanded={query !== '' || expandedLocationId === unit.id}
                  renderIcon={Hospital}
                  label={(
                    <TreeLabel
                      name={unit.name}
                      detail={`${unit.code} · ${formatLocationType(unit.type)}`}
                      status={unit.status}
                      actions={canManageStructure ? (
                        <ButtonSet className="admin-organization__tree-button-set">
                          <Button
                            kind="ghost"
                            size="sm"
                            aria-label={`Add room to ${unit.name}`}
                            onClick={() => onAdd('room', unit.id)}
                          >
                            Add room
                          </Button>
                          {renderActions('unit', unit.id)}
                        </ButtonSet>
                      ) : undefined}
                    />
                  )}
                >
                  {unitRooms.map(renderRoom)}
                </TreeView.TreeNode>
              );
            })}
          </TreeView.TreeNode>
        );
      })}
      {visibleDirectRooms.length > 0 || query === '' ? (
        <TreeView.TreeNode
          id="unassigned-rooms"
          isExpanded={query !== '' || expandedLocationId === 'unassigned-rooms'}
          renderIcon={Location}
          label={(
            <TreeLabel
              name="Unassigned rooms"
              detail={`${visibleDirectRooms.length} ${visibleDirectRooms.length === 1 ? 'room' : 'rooms'}`}
              actions={canManageStructure ? (
                <ButtonSet className="admin-organization__tree-button-set">
                  <Button kind="ghost" size="sm" onClick={() => onAdd('room')}>
                    Add unassigned room
                  </Button>
                </ButtonSet>
              ) : undefined}
            />
          )}
        >
          {visibleDirectRooms.map(renderRoom)}
        </TreeView.TreeNode>
      ) : null}
    </TreeView>
  );

  return (
    <div className="admin-organization__locations-panel">
      <section className="admin-organization__location-browser" aria-label="Location structure">
        <div className="admin-organization__location-browser-heading">
          {canManageStructure ? (
            <Button kind="tertiary" size="sm" onClick={() => onAdd('department')}>Add department</Button>
          ) : null}
        </div>
        <Search
          id="facility-location-search"
          labelText="Search locations"
          placeholder="Search names or codes"
          value={search}
          onChange={(event) => {
            setSearch(event.currentTarget.value);
          }}
          onClear={() => setSearch('')}
        />
        {visibleDepartments.length === 0 && visibleDirectRooms.length === 0 && query ? (
          <EmptyState title="No locations match your search" description="Try another name or code." />
        ) : tree}

      </section>
    </div>
  );
}
