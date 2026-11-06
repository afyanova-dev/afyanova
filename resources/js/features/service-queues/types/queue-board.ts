/**
 * Mirrors the props returned by ServiceQueuesController for Patient Flow.
 * Keep this contract aligned with the backend presenter as the board evolves.
 */
export interface QueueBoardTotals {
  waiting: number;
  inService: number;
  averageWaitMinutes: number | null;
  longestWaitMinutes: number | null;
  longestWaitPatient: string | null;
}

export interface QueueBoardQueue {
  queueId: string;
  queue: string;
  code: string;
  service: string | null;
  location: string | null;
  waiting: number;
  attending: number;
  averageWaitMinutes: number | null;
  longestWaitMinutes: number | null;
  longestWaitPatient: string | null;
  operatingStatus: string;
}

export interface QueueBoardEntry {
  id: string;
  queueId: string;
  ticketNumber: string;
  patientName: string;
  patientNumber: string;
  dateOfBirth: string | null;
  sex: string | null;
  queue: string;
  queueCode: string | null;
  serviceCapability?: string | null;
  sourceOrderRef?: string | null;
  orderType?: string | null;
  priority: string;
  status: string;
  holdReason: string | null;
  waitMinutes: number;
  arrivedAt: string;
  calledRoomId: string | null;
}

export interface QueueBoardRoom {
  id: string;
  name: string;
  code: string;
}

export interface QueueBoardTransferQueue {
  queueId: string;
  queue: string;
}

export interface QueueRoomAssignment {
  queueId: string;
  queue: string;
  code: string;
  roomIds: string[];
  restrictedRoomIds: string[];
}

export interface QueueRoomStaffAssignment {
  queueId: string;
  roomId: string;
  userIds: string[];
}

export interface QueueRoomStaffMember {
  userId: string;
  name: string;
}

export interface QueueBoardDefinition {
  id: string;
  code: string;
  name: string;
  departmentId: string | null;
  serviceCapability: string | null;
  ticketPrefix: string;
  isActive: boolean;
}

export interface QueueBoardDepartment {
  id: string;
  name: string;
  isActive: boolean;
}

export interface ProcedureQueueRouting {
  definitions: Array<{
    definitionId: string;
    code: string;
    name: string;
    queueId: string;
  }>;
  queues: Array<{
    queueId: string;
    queue: string;
    code: string;
  }>;
}

export interface ProcedureProtocolGovernance {
  definitions: Array<{
    definitionId: string;
    code: string;
    name: string;
    version: number;
  }>;
  approvals: Array<{
    approvalId: string;
    definitionId: string;
    definitionVersion: number;
    protocolReference: string;
    protocolVersion: string;
    approvedByUserId: string;
    approvedAt: string;
  }>;
}

export interface QueueBoardProps {
  asOf: string | null;
  totals: QueueBoardTotals;
  queues: QueueBoardQueue[];
  waitingEntries: QueueBoardEntry[];
  calledEntries: QueueBoardEntry[];
  inServiceEntries: QueueBoardEntry[];
  onHoldEntries: QueueBoardEntry[];
  recallableEntries: QueueBoardEntry[];
  rooms: QueueBoardRoom[];
  transferQueues: QueueBoardTransferQueue[];
  queueRoomAssignments: QueueRoomAssignment[];
  myQueueRoomAssignments?: Array<{ queueId: string; roomIds: string[] }>;
}

export interface ServiceQueueManagementProps {
  queueDefinitions: QueueBoardDefinition[];
  queueDepartments: QueueBoardDepartment[];
  serviceCapabilities: string[];
  rooms: QueueBoardRoom[];
  queueRoomAssignments: QueueRoomAssignment[];
  facilityStaff: QueueRoomStaffMember[];
  queueRoomStaffAssignments: QueueRoomStaffAssignment[];
  procedureRouting: ProcedureQueueRouting | null;
  procedureProtocolGovernance: ProcedureProtocolGovernance | null;
}
