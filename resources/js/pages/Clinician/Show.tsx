import { Link, router, useForm } from '@inertiajs/react';
import {
  Button,
  Form,
  IconButton,
  InlineNotification,
  Search,
  Select,
  SelectItem,
  Tag,
  Tab,
  TabListVertical,
  TabPanel,
  TabPanels,
  TabsVertical,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { ConfirmDialog } from '@/components/feedback';
import { FormErrors } from '@/components/ui';
import { SearchableComboBox } from '@/components/ui/SearchableComboBox';
import { AppLayout } from '@/layouts/AppLayout';
import { QueuePatientBanner } from '@/features/clinical/QueuePatientBanner';
import { DockedPanel } from '@/components/panels';
import type { ClinicianShowProps } from '@/features/clinician/types';
import {
  CUSTOM_MEDICATION_DURATION,
  MEDICATION_DURATION_UNITS,
  MEDICATION_FREQUENCY_OPTIONS,
  MEDICATION_ROUTE_OPTIONS,
  OTHER_MEDICATION_OPTION,
} from '@/features/clinical/medication-order-options';
import {
  useClinicalDocumentationDraft,
  useClinicalDraftNavigationGuard,
} from '@/features/clinician/useClinicalDocumentationDraft';
import { Add, Checkmark, ChevronDown, Document, Edit, HealthCross, Medication, Microscope, Play, Report, Task, TrashCan } from '@/icons';

type NoteForm = {
  patient_id: string;
  note_type: string;
  body: string;
  note_error: string;
};

type DiagnosisForm = {
  patient_id: string;
  description: string;
  code: string;
  code_system: string;
  clinical_status: string;
  diagnosis_error: string;
};

type PlanForm = {
  patient_id: string;
  plan_type: string;
  clinical_intent: string;
  plan_error: string;
};

type ProcedureForm = {
  patient_id: string;
  definition_id: string;
  indication: string;
};

type DispositionForm = {
  disposition: string;
  disposition_error: string;
};

type MedicationCatalogItem = ClinicianShowProps['medicationCatalog'][number];
type DocumentationSection = 'note' | 'diagnosis' | 'plan';
type WorkspaceTab = 'documentation' | 'orders' | 'plan' | 'record';
type OrderPicker = 'drug' | 'lab';

function medicationDuration(entry: BasketEntry): string {
  if (entry.durationUnit === CUSTOM_MEDICATION_DURATION) {
    return (entry.customDuration ?? '').trim();
  }

  const durationUnit = MEDICATION_DURATION_UNITS.find((unit) => unit.value === entry.durationUnit);
  const durationValue = (entry.durationValue ?? '').trim();

  if (!durationUnit || !durationValue) {
    return '';
  }

  return `${durationValue} ${Number(durationValue) === 1 ? durationUnit.singular : durationUnit.plural}`;
}

function isMedicationOrderComplete(entry: BasketEntry): boolean {
  const durationValue = Number(entry.durationValue ?? '');
  const hasValidDuration = entry.durationUnit === CUSTOM_MEDICATION_DURATION
    ? (entry.customDuration ?? '').trim() !== ''
    : Number.isFinite(durationValue) && durationValue > 0;
  const quantityValue = (entry.quantityValue ?? '').trim();
  const hasValidQuantity = quantityValue === ''
    || (Number.isFinite(Number(quantityValue)) && Number(quantityValue) > 0);

  return Boolean(
    entry.dose.trim()
    && entry.route.trim()
    && (!entry.routeCustom || entry.route.trim())
    && entry.frequency.trim()
    && (!entry.frequencyCustom || entry.frequency.trim())
    && hasValidDuration
    && medicationDuration(entry)
    && hasValidQuantity,
  );
}
type BasketEntry = MedicationCatalogItem & {
  basketId: string;
  idempotencyKey: string;
  outcomeUncertain: boolean;
  dose: string;
  route: string;
  routeCustom: boolean;
  frequency: string;
  frequencyCustom: boolean;
  durationValue: string;
  durationUnit: string;
  customDuration: string;
  quantityValue: string;
  indication: string;
  dosingInstruction: string;
};
type LabCatalogEntry = {
  id: string;
  type: 'test' | 'panel';
  code: string;
  name: string;
  category: string | null;
  specimenType: string | null;
};
type LabOrderAttempt = {
  orderId: string;
  idempotencyKey: string;
  submitIdempotencyKey: string;
};

const WORKSPACE_TABS: WorkspaceTab[] = ['documentation', 'orders', 'plan', 'record'];

function truncate(value: string, length = 180): string {
  return value.length > length ? `${value.slice(0, length).trimEnd()}…` : value;
}

function draftStatusLabel(
  statuses: Array<'idle' | 'unsaved' | 'saving' | 'saved' | 'error'>,
  online: boolean,
  latestSavedAt: string | null,
): string {
  if (!online) return 'Offline · unsaved edits remain on this page until the connection returns';
  if (statuses.includes('error')) return 'Draft not saved';
  if (statuses.includes('saving')) return 'Saving draft…';
  if (statuses.includes('unsaved')) return 'Draft has unsaved changes';
  if (latestSavedAt) return `Draft saved ${formatDate(latestSavedAt, true)}`;
  return 'Draft ready to record';
}

function formatDate(value: string | null, includeTime = false): string {
  if (!value) return 'Not recorded';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, includeTime
      ? { dateStyle: 'medium', timeStyle: 'short' }
      : { dateStyle: 'medium' }).format(date);
}

function restoreNoteText(draft: ClinicianShowProps['drafts']['note']): string {
  const title = draft?.values.title?.trim();
  const body = draft?.values.body?.trim();

  return [title, body].filter((value): value is string => Boolean(value)).join('\n\n');
}

function restorePlanText(draft: ClinicianShowProps['drafts']['plan']): string {
  if (!draft) {
    return '';
  }

  const legacyDetails = [
    ['Goals', draft.values.goals],
    ['Planned actions', draft.values.planned_actions],
    ['Instructions', draft.values.instructions],
    ['Follow-up', draft.values.follow_up],
  ]
    .filter((detail): detail is [string, string] => Boolean(detail[1]?.trim()))
    .map(([label, value]) => `${label}: ${value.trim()}`);

  return [draft.values.clinical_intent?.trim(), ...legacyDetails]
    .filter((value): value is string => Boolean(value))
    .join('\n\n');
}

export default function ClinicianShow(props: ClinicianShowProps): ReactNode {
  const [selectedWorkspaceTab, setSelectedWorkspaceTab] = useState<WorkspaceTab>(() => {
    if (props.canRecordNote || props.canRecordDiagnosis) {
      return 'documentation';
    }

    if (props.canRecordPrescribing || props.canReadPrescribing || props.canRecordLabOrders || props.canRequestProcedure) {
      return 'orders';
    }

    if (props.canRecordPlan || props.canChangeDisposition || props.canCompleteEncounter) {
      return 'plan';
    }

    return 'record';
  });
  const [dispositionSaved, setDispositionSaved] = useState(false);
  const [encounterActionError, setEncounterActionError] = useState<string | null>(null);
  const [completing, setCompleting] = useState(false);
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false);
  const [discardTarget, setDiscardTarget] = useState<DocumentationSection | null>(null);
  const [discardError, setDiscardError] = useState<{ section: DocumentationSection; message: string } | null>(null);
  const [discardingDraft, setDiscardingDraft] = useState(false);
  const [discardedDraftSections, setDiscardedDraftSections] = useState<DocumentationSection[]>([]);
  const [authorizingProcedureId, setAuthorizingProcedureId] = useState<string | null>(null);
  const [procedureActionError, setProcedureActionError] = useState<string | null>(null);
  const [medicationSearch, setMedicationSearch] = useState('');
  const [basketEntries, setBasketEntries] = useState<BasketEntry[]>([]);
  const [drugOrdersExpanded, setDrugOrdersExpanded] = useState(false);
  const [activeOrderPicker, setActiveOrderPicker] = useState<OrderPicker | null>(null);
  const [activeMedicationFormBasketId, setActiveMedicationFormBasketId] = useState<string | null>(null);
  const [labSearch, setLabSearch] = useState('');
  const [labBasketEntries, setLabBasketEntries] = useState<LabCatalogEntry[]>([]);
  const [labOrdersExpanded, setLabOrdersExpanded] = useState(false);
  const [labPriority, setLabPriority] = useState('ROUTINE');
  const [labClinicalIndication, setLabClinicalIndication] = useState('');
  const [labOrderAttempt, setLabOrderAttempt] = useState<LabOrderAttempt | null>(null);
  const [labSubmitting, setLabSubmitting] = useState(false);
  const [labOrderError, setLabOrderError] = useState<string | null>(null);
  const [labOrderSuccess, setLabOrderSuccess] = useState<string | null>(null);
  const [basketSigning, setBasketSigning] = useState(false);
  const [basketProgress, setBasketProgress] = useState<{ done: number; total: number } | null>(null);
  const [basketError, setBasketError] = useState<string | null>(null);
  const [signingNoteId, setSigningNoteId] = useState<string | null>(null);
  const [signingNote, setSigningNote] = useState(false);
  const [noteActionError, setNoteActionError] = useState<string | null>(null);
  const [amendmentNoteId, setAmendmentNoteId] = useState<string | null>(null);
  const [amendmentSubmitting, setAmendmentSubmitting] = useState(false);
  const [amendmentDiscardDialogOpen, setAmendmentDiscardDialogOpen] = useState(false);
  const [amendmentErrors, setAmendmentErrors] = useState<Record<string, string>>({});
  const [amendmentForm, setAmendmentForm] = useState({
    amendment_type: 'ADDENDUM',
    body: '',
    reason: '',
  });
  const [isOnline, setIsOnline] = useState(() => (
    typeof navigator === 'undefined' ? true : navigator.onLine
  ));
  const hasUncertainBasketEntry = basketEntries.some((entry) => entry.outcomeUncertain);
  const noteDraft = props.drafts?.note ?? null;
  const diagnosisDraft = props.drafts?.diagnosis ?? null;
  const planDraft = props.drafts?.plan ?? null;
  const noteForm = useForm<NoteForm>({
    patient_id: props.patient.patientId,
    note_type: noteDraft?.values.note_type ?? 'CONSULTATION',
    body: restoreNoteText(noteDraft),
    note_error: '',
  });
  const diagnosisForm = useForm<DiagnosisForm>({
    patient_id: props.patient.patientId,
    description: diagnosisDraft?.values.description ?? '',
    code: diagnosisDraft?.values.code ?? '',
    code_system: diagnosisDraft?.values.code_system ?? '',
    clinical_status: diagnosisDraft?.values.clinical_status ?? 'WORKING',
    diagnosis_error: '',
  });
  const planForm = useForm<PlanForm>({
    patient_id: props.patient.patientId,
    plan_type: planDraft?.values.plan_type ?? 'GENERAL',
    clinical_intent: restorePlanText(planDraft),
    plan_error: '',
  });
  const procedureForm = useForm<ProcedureForm>({
    patient_id: props.patient.patientId,
    definition_id: props.procedureDefinitions[0]?.definitionId ?? '',
    indication: '',
  });
  const dispositionForm = useForm<DispositionForm>({
    disposition: 'FOLLOW_UP',
    disposition_error: '',
  });
  const active = props.encounter.status === 'ACTIVE';
  const noteHasContent = Boolean(noteForm.data.body.trim());
  const diagnosisHasContent = Boolean(diagnosisForm.data.description.trim() || diagnosisForm.data.code.trim());
  const planHasContent = Boolean(planForm.data.clinical_intent.trim());
  const diagnosisCatalog = props.diagnosisCatalog ?? [];
  const diagnosisItems = diagnosisCatalog.map((diagnosis) => ({
    id: `${diagnosis.codeSystem}:${diagnosis.code}`,
    label: `${diagnosis.name} · ${diagnosis.code}`,
    searchText: `${diagnosis.aliases} ${diagnosis.category} ${diagnosis.codeSystem}`,
  }));
  const selectedDiagnosisId = diagnosisForm.data.code
    ? `${diagnosisForm.data.code_system}:${diagnosisForm.data.code}`
    : '';
  const returnTo = typeof window === 'undefined'
    ? routes.workspaces.clinician
    : `${window.location.pathname}${window.location.search}`;

  const noteDocumentation = useClinicalDocumentationDraft({
    encounterId: props.encounter.encounterId,
    patientId: props.patient.patientId,
    section: 'NOTE',
    enabled: active && props.canRecordNote,
    values: {
      note_type: noteForm.data.note_type,
      body: noteForm.data.body,
    },
    hasContent: noteHasContent,
    initialSavedAt: noteDraft?.savedAt ?? null,
  });
  const diagnosisDocumentation = useClinicalDocumentationDraft({
    encounterId: props.encounter.encounterId,
    patientId: props.patient.patientId,
    section: 'DIAGNOSIS',
    enabled: active && props.canRecordDiagnosis,
    values: {
      description: diagnosisForm.data.description,
      code: diagnosisForm.data.code,
      code_system: diagnosisForm.data.code_system,
      clinical_status: diagnosisForm.data.clinical_status,
    },
    hasContent: diagnosisHasContent,
    initialSavedAt: diagnosisDraft?.savedAt ?? null,
  });
  const planDocumentation = useClinicalDocumentationDraft({
    encounterId: props.encounter.encounterId,
    patientId: props.patient.patientId,
    section: 'PLAN',
    enabled: active && props.canRecordPlan,
    values: {
      plan_type: planForm.data.plan_type,
      clinical_intent: planForm.data.clinical_intent,
    },
    hasContent: planHasContent,
    initialSavedAt: planDraft?.savedAt ?? null,
  });
  const documentationDrafts = [noteDocumentation, diagnosisDocumentation, planDocumentation];
  const latestDraftSavedAt = documentationDrafts
    .map((draft) => draft.savedAt)
    .filter((value): value is string => Boolean(value))
    .sort()
    .at(-1) ?? null;
  const hasUnsavedDraft = documentationDrafts.some(
    (draft) => draft.status === 'unsaved' || draft.status === 'saving' || draft.status === 'error',
  );
  const draftStatusText = draftStatusLabel(
    documentationDrafts.map((draft) => draft.status),
    isOnline,
    latestDraftSavedAt,
  );
  const recordedCount = props.notes.length + props.diagnoses.length + props.plans.length;
  const orderCount = props.prescribingDecisions.length + props.procedures.length;
  const encounterRecordCount = recordedCount + orderCount;
  const nursingNotes = props.notes.filter((note) => note.authoredContext === 'NURSING');
  const noteToSign = props.notes.find((note) => note.clinicalNoteId === signingNoteId) ?? null;
  const noteToAmend = props.notes.find((note) => note.clinicalNoteId === amendmentNoteId) ?? null;
  const canRecordAny = active && (props.canRecordNote || props.canRecordDiagnosis || props.canRecordPlan);
  const canAccessDrugOrders = active && (props.canRecordPrescribing || props.canReadPrescribing);
  const canAccessOrderBasket = active && (
    canAccessDrugOrders || props.canRecordLabOrders
  );
  const activeMedicationFormEntry = basketEntries.find(
    (entry) => entry.basketId === activeMedicationFormBasketId,
  ) ?? null;
  const labCatalogEntries: LabCatalogEntry[] = [
    ...props.labTestCatalog.map((definition) => ({
      id: definition.definitionId,
      type: 'test' as const,
      code: definition.code,
      name: definition.name,
      category: definition.category,
      specimenType: definition.specimenType,
    })),
    ...props.labPanelCatalog.map((panel) => ({
      id: panel.panelId,
      type: 'panel' as const,
      code: panel.code,
      name: panel.name,
      category: panel.category,
      specimenType: null,
    })),
  ];
  const matchingLabCatalogEntries = labCatalogEntries.filter((entry) => (
    `${entry.name} ${entry.code} ${entry.category ?? ''} ${entry.specimenType ?? ''}`
      .toLowerCase()
      .includes(labSearch.trim().toLowerCase())
  ));
  const matchingMedicationCatalogEntries = props.medicationCatalog.filter((item) => (
    `${item.name} ${item.code}`.toLowerCase().includes(medicationSearch.trim().toLowerCase())
  ));
  useClinicalDraftNavigationGuard(
    hasUnsavedDraft
    || basketEntries.length > 0
    || labBasketEntries.length > 0
    || Boolean(amendmentForm.body.trim() || amendmentForm.reason.trim()),
  );
  function retryDrafts(): void {
    documentationDrafts.forEach((draft) => draft.saveNow());
  }

  function selectWorkspaceTab(tab: WorkspaceTab): void {
    setSelectedWorkspaceTab(tab);
  }

  async function discardDocumentationDraft(): Promise<void> {
    const section = discardTarget;
    if (!section || discardingDraft) {
      return;
    }

    if (!isOnline) {
      setDiscardError({
        section,
        message: 'Reconnect before discarding. The server draft has not been removed.',
      });
      setDiscardTarget(null);
      return;
    }

    const draft = section === 'note'
      ? noteDocumentation
      : section === 'diagnosis'
        ? diagnosisDocumentation
        : planDocumentation;

    setDiscardingDraft(true);
    setDiscardError(null);

    try {
      await draft.discard();

      if (section === 'note') {
        noteForm.setData('body', '');
      } else if (section === 'diagnosis') {
        diagnosisForm.setData('description', '');
        diagnosisForm.setData('code', '');
        diagnosisForm.setData('code_system', '');
        diagnosisForm.setData('clinical_status', 'WORKING');
      } else {
        planForm.setData('plan_type', 'GENERAL');
        planForm.setData('clinical_intent', '');
      }

      draft.resume();
      setDiscardedDraftSections((current) => (
        current.includes(section) ? current : [...current, section]
      ));
      setDiscardTarget(null);
    } catch (caught) {
      draft.resume();
      setDiscardError({
        section,
        message: caught instanceof Error
          ? `The draft could not be discarded. Your entries are unchanged. ${caught.message}`
          : 'The draft could not be discarded. Your entries are unchanged. Check your connection and try again.',
      });
      setDiscardTarget(null);
    } finally {
      setDiscardingDraft(false);
    }
  }

  useEffect(() => {
    if (typeof window === 'undefined') return;

    function updateOnlineStatus(): void {
      setIsOnline(window.navigator.onLine);
    }

    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    return () => {
      window.removeEventListener('online', updateOnlineStatus);
      window.removeEventListener('offline', updateOnlineStatus);
    };
  }, []);

  useEffect(() => {
    function onWorkspaceShortcut(event: KeyboardEvent): void {
      if (!(event.ctrlKey || event.metaKey) && !event.altKey) return;

      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        const focusedSection = document.activeElement?.closest('.clinician-documentation-section');
        focusedSection?.querySelector<HTMLFormElement>('form')?.requestSubmit();
        return;
      }

      if (!event.altKey) return;
      const target = event.key.toLowerCase();
      const targetSection = target === 'n'
        ? { id: 'clinician-note', tab: 'documentation' as const }
        : target === 'd'
          ? { id: 'clinician-diagnosis', tab: 'documentation' as const }
          : target === 'p'
            ? { id: 'clinician-plan', tab: 'plan' as const }
            : target === 'm'
              ? { id: 'clinician-medication-search', tab: 'orders' as const }
              : null;
      if (!targetSection) return;

      event.preventDefault();
      selectWorkspaceTab(targetSection.tab);
      if (target === 'm') openOrderPicker('drug');
      window.requestAnimationFrame(() => {
        const section = document.getElementById(targetSection.id);
        section?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        const field = targetSection.id === 'clinician-medication-search'
          ? section
          : section?.querySelector<HTMLInputElement | HTMLTextAreaElement>('input, textarea');
        field?.focus();
      });
    }

    document.addEventListener('keydown', onWorkspaceShortcut);
    return () => document.removeEventListener('keydown', onWorkspaceShortcut);
  }, []);

  function startEncounter(): void {
    setEncounterActionError(null);
    router.post(routes.workspaces.receptionStart(props.encounter.encounterId), { return_to: returnTo }, {
      onError: (errors) => setEncounterActionError(errors.start ?? 'The encounter could not be started.'),
    });
  }

  function addMedicationToBasket(item: MedicationCatalogItem): string {
    setDrugOrdersExpanded(true);
    const stagedEntry = basketEntries.find((entry) => entry.id === item.id);
    if (stagedEntry) {
      return stagedEntry.basketId;
    }

    const basketId = crypto.randomUUID();
    setBasketEntries((current) => current.some((entry) => entry.id === item.id)
      ? current
      : [...current, {
        ...item,
        basketId,
        idempotencyKey: crypto.randomUUID(),
        outcomeUncertain: false,
        dose: '',
        route: '',
        routeCustom: false,
        frequency: '',
        frequencyCustom: false,
        durationValue: '',
        durationUnit: '',
        customDuration: '',
        quantityValue: '',
        indication: '',
        dosingInstruction: '',
      }]);
    return basketId;
  }

  function openMedicationOrderForm(item: MedicationCatalogItem): void {
    const basketId = addMedicationToBasket(item);
    setActiveMedicationFormBasketId(basketId);
  }

  function openStagedMedicationOrderForm(basketId: string): void {
    setDrugOrdersExpanded(true);
    setActiveOrderPicker('drug');
    setActiveMedicationFormBasketId(basketId);
  }

  function openOrderPicker(picker: OrderPicker): void {
    setMedicationSearch('');
    setLabSearch('');
    setActiveMedicationFormBasketId(null);
    setActiveOrderPicker(picker);
  }

  function closeOrderPicker(): void {
    setActiveOrderPicker(null);
    setActiveMedicationFormBasketId(null);
    setMedicationSearch('');
    setLabSearch('');
  }

  function removeMedicationFromBasket(basketId: string): void {
    setBasketEntries((current) => current.filter((entry) => entry.basketId !== basketId));
    if (basketEntries.length === 1 && basketEntries[0]?.basketId === basketId) {
      setDrugOrdersExpanded(false);
    }
    setActiveMedicationFormBasketId((current) => current === basketId ? null : current);
  }

  function addLabEntry(entry: LabCatalogEntry): void {
    setLabOrdersExpanded(true);
    setLabBasketEntries((current) => current.some((existing) => existing.type === entry.type && existing.id === entry.id)
      ? current
      : [...current, entry]);
  }

  function removeLabEntry(entry: LabCatalogEntry): void {
    setLabBasketEntries((current) => current.filter(
      (existing) => existing.type !== entry.type || existing.id !== entry.id,
    ));
    if (labBasketEntries.length === 1 && labBasketEntries[0]?.id === entry.id && labBasketEntries[0]?.type === entry.type) {
      setLabOrdersExpanded(false);
    }
  }

  function submitLabOrders(event: FormEvent): void {
    event.preventDefault();
    if (labBasketEntries.length === 0 || labSubmitting) {
      return;
    }

    const attempt = labOrderAttempt ?? {
      orderId: crypto.randomUUID(),
      idempotencyKey: crypto.randomUUID(),
      submitIdempotencyKey: crypto.randomUUID(),
    };
    setLabOrderAttempt(attempt);
    setLabOrderError(null);
    setLabOrderSuccess(null);
    setLabSubmitting(true);

    router.post(routes.workspaces.encounterLabOrder(props.encounter.encounterId), {
      order_id: attempt.orderId,
      idempotency_key: attempt.idempotencyKey,
      submit_idempotency_key: attempt.submitIdempotencyKey,
      patient_id: props.patient.patientId,
      test_definition_ids: labBasketEntries
        .filter((entry) => entry.type === 'test')
        .map((entry) => entry.id),
      panel_ids: labBasketEntries
        .filter((entry) => entry.type === 'panel')
        .map((entry) => entry.id),
      priority: labPriority,
      clinical_indication_text: labClinicalIndication.trim() || null,
    }, {
      onSuccess: () => {
        setLabBasketEntries([]);
        setLabOrdersExpanded(false);
        setLabOrderAttempt(null);
        setLabSearch('');
        setLabPriority('ROUTINE');
        setLabClinicalIndication('');
        setLabOrderSuccess('Lab order submitted.');
      },
      onError: (errors) => {
        const firstError = Object.values(errors).find((error): error is string => typeof error === 'string');
        setLabOrderError(errors.lab_order_error ?? firstError ?? 'The lab order could not be submitted. Retry it unchanged.');
      },
      onFinish: () => setLabSubmitting(false),
    });
  }

  function updateBasketEntry<Field extends (
    | 'dose'
    | 'route'
    | 'routeCustom'
    | 'frequency'
    | 'frequencyCustom'
    | 'durationValue'
    | 'durationUnit'
    | 'customDuration'
    | 'quantityValue'
    | 'indication'
    | 'dosingInstruction'
  )>(
    basketId: string,
    field: Field,
    value: BasketEntry[Field],
  ): void {
    setBasketEntries((current) => current.map((entry) => (
      entry.basketId === basketId
        ? { ...entry, [field]: value, idempotencyKey: crypto.randomUUID() }
        : entry
    )));
  }

  const basketComplete = basketEntries.length > 0 && basketEntries.every((entry) => (
    isMedicationOrderComplete(entry)
  ));

  async function signMedicationBasket(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (!basketComplete || basketSigning) return;

    setBasketError(null);
    setEncounterActionError(null);
    const pending = [...basketEntries];
    setBasketSigning(true);

    let signedCount = 0;
    let failureMessage: string | null = null;
    for (const entry of pending) {
      setBasketProgress({ done: signedCount, total: pending.length });
      const succeeded = await new Promise<boolean>((resolve) => {
        let settled = false;
        const settle = (result: boolean): void => {
          if (!settled) {
            settled = true;
            resolve(result);
          }
        };

        router.post(routes.workspaces.encounterMedication(props.encounter.encounterId), {
          idempotency_key: entry.idempotencyKey,
          patient_id: props.patient.patientId,
          medication_name: entry.name,
          medication_code: entry.code,
          medication_code_system: 'AfyaNova Inventory',
          dose: entry.dose.trim(),
          route: entry.route.trim(),
          frequency: entry.frequency.trim(),
          duration: medicationDuration(entry),
          quantity: '',
          quantity_value: entry.quantityValue.trim() || '',
          quantity_unit: entry.unitOfMeasure,
          dosing_instruction: entry.dosingInstruction.trim(),
          therapeutic_intent: '',
          indication: entry.indication.trim(),
          urgency: 'ROUTINE',
          occurred_at: '',
          reason: '',
        }, {
          preserveScroll: true,
          onSuccess: () => settle(true),
          onError: (errors) => {
            failureMessage = Object.values(errors)[0]?.toString() ?? 'A drug order could not be added.';
            setBasketEntries((current) => current.map((item) => (
              item.basketId === entry.basketId ? { ...item, outcomeUncertain: false } : item
            )));
            settle(false);
          },
          onFinish: () => {
            if (!settled) {
              failureMessage = 'The result was not confirmed. Retry this unchanged order to safely resolve it.';
              setBasketEntries((current) => current.map((item) => (
                item.basketId === entry.basketId ? { ...item, outcomeUncertain: true } : item
              )));
              settle(false);
            }
          },
        });
      });

      if (!succeeded) {
        setBasketProgress(null);
        setBasketSigning(false);
        setBasketError(
          `${signedCount} of ${pending.length} orders recorded. `
          + `${pending.length - signedCount} remain in the basket; sign again to retry only those.`
          + (failureMessage ? ` ${failureMessage}` : ''),
        );
        return;
      }

      signedCount += 1;
      setBasketEntries((current) => current.filter((item) => item.basketId !== entry.basketId));
      setBasketProgress({ done: signedCount, total: pending.length });
    }

    setBasketEntries([]);
    setDrugOrdersExpanded(false);
    setMedicationSearch('');
    setBasketProgress(null);
    setBasketSigning(false);
  }

  function requestNoteSignature(noteId: string): void {
    setNoteActionError(null);
    setSigningNoteId(noteId);
  }

  function signSavedNote(): void {
    if (!signingNoteId || signingNote) return;

    let settled = false;
    setSigningNote(true);
    router.post(routes.workspaces.clinicalNoteSign(signingNoteId), {}, {
      preserveScroll: true,
      onSuccess: () => {
        settled = true;
        setNoteActionError(null);
        setSigningNoteId(null);
      },
      onError: (errors) => {
        settled = true;
        setNoteActionError(Object.values(errors)[0]?.toString() ?? 'The note could not be signed.');
        setSigningNoteId(null);
      },
      onFinish: () => {
        if (!settled) {
          setNoteActionError('The signing result was not confirmed. Review the saved note before retrying.');
          setSigningNoteId(null);
        }
        setSigningNote(false);
      },
    });
  }

  function openAmendmentPanel(noteId: string): void {
    setAmendmentDiscardDialogOpen(false);
    setNoteActionError(null);
    setAmendmentErrors({});
    setAmendmentForm({ amendment_type: 'ADDENDUM', body: '', reason: '' });
    setAmendmentNoteId(noteId);
  }

  function closeAmendmentPanel(): void {
    if (amendmentSubmitting) return;
    if (amendmentForm.body.trim() || amendmentForm.reason.trim()) {
      setAmendmentDiscardDialogOpen(true);
      return;
    }
    setAmendmentNoteId(null);
    setAmendmentErrors({});
  }

  function discardAmendmentDraft(): void {
    setAmendmentForm({ amendment_type: 'ADDENDUM', body: '', reason: '' });
    setAmendmentErrors({});
    setNoteActionError(null);
    setAmendmentNoteId(null);
    setAmendmentDiscardDialogOpen(false);
  }

  function submitNoteAmendment(event: FormEvent): void {
    event.preventDefault();
    if (!amendmentNoteId || amendmentSubmitting) return;

    let settled = false;
    setNoteActionError(null);
    setAmendmentErrors({});
    setAmendmentSubmitting(true);
    router.post(routes.workspaces.clinicalNoteAmend(amendmentNoteId), amendmentForm, {
      preserveScroll: true,
      onSuccess: () => {
        settled = true;
        setAmendmentForm({ amendment_type: 'ADDENDUM', body: '', reason: '' });
        setAmendmentNoteId(null);
      },
      onError: (errors) => {
        settled = true;
        setAmendmentErrors(errors);
        setNoteActionError(Object.values(errors)[0]?.toString() ?? 'The amendment could not be saved.');
      },
      onFinish: () => {
        if (!settled) {
          setNoteActionError('The amendment result was not confirmed. Review the note before retrying.');
        }
        setAmendmentSubmitting(false);
      },
    });
  }

  function createNote(event: FormEvent): void {
    event.preventDefault();
    let saved = false;
    noteDocumentation.pause();
    noteForm.post(routes.workspaces.encounterNote(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => {
        saved = true;
        noteDocumentation.clear();
        noteForm.setData('body', '');
      },
      onFinish: () => {
        if (!saved) {
          noteDocumentation.resume();
        }
      },
    });
  }

  function recordDiagnosis(event: FormEvent): void {
    event.preventDefault();
    let saved = false;
    diagnosisDocumentation.pause();
    diagnosisForm.post(routes.workspaces.encounterDiagnosis(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => {
        saved = true;
        diagnosisDocumentation.clear();
        diagnosisForm.setData('description', '');
        diagnosisForm.setData('code', '');
        diagnosisForm.setData('code_system', '');
      },
      onFinish: () => {
        if (!saved) {
          diagnosisDocumentation.resume();
        }
      },
    });
  }

  function createPlan(event: FormEvent): void {
    event.preventDefault();
    let saved = false;
    planDocumentation.pause();
    planForm.post(routes.workspaces.encounterPlan(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => {
        saved = true;
        planDocumentation.clear();
        planForm.setData('clinical_intent', '');
      },
      onFinish: () => {
        if (!saved) {
          planDocumentation.resume();
        }
      },
    });
  }

  function requestProcedure(event: FormEvent): void {
    event.preventDefault();
    procedureForm.post(routes.workspaces.encounterProcedureRequest(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => procedureForm.reset('indication'),
    });
  }

  function authorizeProcedure(procedureId: string): void {
    setProcedureActionError(null);
    setAuthorizingProcedureId(procedureId);
    router.post(
      routes.workspaces.procedureAuthorize(props.encounter.encounterId, procedureId),
      {},
      {
        preserveScroll: true,
        onError: (errors) => setProcedureActionError(
          errors.authorize_error ?? 'The procedure could not be authorized.',
        ),
        onFinish: () => setAuthorizingProcedureId(null),
      },
    );
  }

  function saveDisposition(event: FormEvent): void {
    event.preventDefault();
    dispositionForm.post(routes.workspaces.receptionDisposition(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => setDispositionSaved(true),
    });
  }

  function completeEncounter(): void {
    setEncounterActionError(null);
    setCompleting(true);
    router.post(routes.workspaces.receptionComplete(props.encounter.encounterId), { return_to: routes.workspaces.clinician }, {
      onError: (errors) => setEncounterActionError(errors.complete ?? 'The encounter could not be completed.'),
      onFinish: () => setCompleting(false),
    });
  }

  return (
    <AppLayout>
      <div className={`clinical-entry-shell clinician-consultation${active ? ' clinical-entry-shell--active clinician-consultation--active' : ''}`}>
      <QueuePatientBanner
        workflow="consultation"
        patient={props.patient}
        entry={props.entry}
        queueNavigation={props.queueNavigation}
        queueHref={routes.workspaces.clinician}
        queueLabel="Open consultation queue"
        entryHref={routes.workspaces.clinicianEntry}
        encounter={{
          encounterType: props.encounter.type,
          status: props.encounter.status,
        }}
        chartHref={routes.patient.chart(props.patient.patientId)}
      />
      {encounterActionError ? <InlineNotification kind="error" title={encounterActionError} lowContrast /> : null}
      <div className={`clinical-entry-workspace clinician-workspace clinician-workspace--${selectedWorkspaceTab}${active ? ' clinician-workspace--active' : ''}`}>
        <aside className="clinical-entry-workspace__sidebar clinician-workspace__sidebar" aria-label="Patient safety and encounter summary">
          {active ? (
            <div className="afy-card clinician-panel clinician-draft-status clinician-draft-status--card" aria-label="Draft save status" aria-live="polite">
              <span>{canRecordAny ? draftStatusText : 'Read-only encounter'}</span>
              {canRecordAny && isOnline && documentationDrafts.some((draft) => draft.status === 'error') ? (
                <Button kind="ghost" size="sm" onClick={retryDrafts}>Retry saving</Button>
              ) : null}
            </div>
          ) : null}
          <section className="afy-card clinician-panel clinician-safety" aria-labelledby="clinician-safety-heading">
            <div className="clinician-context__section-heading">
              <h2 id="clinician-safety-heading" className="clinician-panel__subtitle">Safety snapshot</h2>
              <span className="clinician-context__meta">Active record</span>
            </div>
            <div className={props.allergies.length > 0 || !props.canReadAllergies ? 'clinician-safety__row clinician-safety__row--alert' : 'clinician-safety__row'}>
              <span className="clinician-safety__label">Allergies</span>
              <div className="clinician-safety__value">
                {!props.canReadAllergies ? (
                  <span className="clinician-safety__alert-message">Unavailable. Verify before prescribing.</span>
                ) : props.allergies.length === 0 ? (
                  <Tag size="sm" type="cool-gray">No recorded active allergies</Tag>
                ) : props.allergies.map((allergy) => (
                  <Tag
                    key={allergy.allergyId}
                    size="sm"
                    type={allergy.severity === 'SEVERE' || allergy.severity === 'LIFE_THREATENING' ? 'red' : 'magenta'}
                  >
                    {allergy.substanceName}
                    {allergy.reactionDescription ? ` · ${truncate(allergy.reactionDescription, 40)}` : ''}
                  </Tag>
                ))}
              </div>
            </div>
            <div className="clinician-safety__row">
              <span className="clinician-safety__label">Problems</span>
              <div className="clinician-safety__value">
                {!props.canReadProblems ? (
                  <span className="clinician-safety__muted">Not available</span>
                ) : props.problems.length === 0 ? (
                  <span className="clinician-safety__muted">No active problems recorded</span>
                ) : (
                  <>
                    <span>{props.problems.slice(0, 3).map((problem) => problem.description).join(' · ')}</span>
                    {props.problems.length > 3 ? <span className="clinician-safety__muted">+{props.problems.length - 3} more</span> : null}
                    <Link className="clinician-safety__link" href={routes.patient.chart(props.patient.patientId, 'conditions')} aria-label="View conditions in the patient chart">View</Link>
                  </>
                )}
              </div>
            </div>
            <div className="clinician-safety__row">
              <span className="clinician-safety__label">Medications</span>
              <div className="clinician-safety__value">
                {!props.canReadMedications ? (
                  <span className="clinician-safety__muted">Not available</span>
                ) : props.medications.length === 0 ? (
                  <span className="clinician-safety__muted">No medications ordered this visit</span>
                ) : (
                  <>
                    <span>
                      {props.medications.slice(0, 2).map((medication) => (
                        `${medication.medicationName ?? 'Medication'}${medication.dose ? ` ${medication.dose}` : ''}`
                      )).join(' · ')}
                    </span>
                    {props.medications.length > 2 ? <span className="clinician-safety__muted">+{props.medications.length - 2} more</span> : null}
                    <Link className="clinician-safety__link" href={routes.patient.chart(props.patient.patientId, 'medications')} aria-label="View medications in the patient chart">View</Link>
                  </>
                )}
              </div>
            </div>
          </section>

          <section className="afy-card clinician-panel clinician-sidebar-card" aria-labelledby="clinician-vitals-heading">
            <div className="clinician-context__section-heading">
              <h2 id="clinician-vitals-heading" className="clinician-panel__subtitle">Vital signs</h2>
              {props.vitals[0] ? (
                <span className="clinician-context__meta">{formatDate(props.vitals[0].occurredAt, true)}</span>
              ) : null}
            </div>
            {props.vitals.length === 0 ? (
              <p className="clinician-context__empty">No vital signs are recorded.</p>
            ) : props.vitals.map((vital, index) => (
              <div key={`${vital.occurredAt}-${index}`} className="clinician-vitals">
                <dl className="clinician-vitals__measurements">
                  <div><dt>Temperature</dt><dd>{vital.temperature ?? '—'} <small>°C</small></dd></div>
                  <div><dt>Pulse</dt><dd>{vital.heartRate ?? '—'} <small>bpm</small></dd></div>
                  <div><dt>Blood pressure</dt><dd>{vital.systolicBp ?? '—'}/{vital.diastolicBp ?? '—'} <small>mmHg</small></dd></div>
                  <div><dt>SpO₂</dt><dd>{vital.oxygenSaturation ?? '—'} <small>%</small></dd></div>
                  <div><dt>Resp. rate</dt><dd>{vital.respiratoryRate ?? '—'} <small>/min</small></dd></div>
                  <div><dt>Pain</dt><dd>{vital.painScore ?? '—'} <small>/10</small></dd></div>
                  <div><dt>Weight</dt><dd>{vital.weight ?? '—'} <small>kg</small></dd></div>
                  <div><dt>Height</dt><dd>{vital.height ?? '—'} <small>cm</small></dd></div>
                </dl>
              </div>
            ))}
          </section>

          <section className="afy-card clinician-panel clinician-sidebar-card" aria-labelledby="clinician-handoff-heading">
            <div className="clinician-context__section-heading">
              <h2 id="clinician-handoff-heading" className="clinician-panel__subtitle">Nursing handoff</h2>
              {props.triage ? (
                <Tag type={props.triage.priority === 'EMERGENCY' ? 'red' : props.triage.priority === 'URGENT' ? 'purple' : 'blue'}>
                  {props.triage.priority === 'ROUTINE' ? 'Normal' : props.triage.priority}
                </Tag>
              ) : null}
            </div>
            {props.triage ? (
              <>
                <p className="clinician-triage__complaint">{props.triage.chiefComplaint}</p>
                <dl className="clinician-triage__details">
                  {props.triage.acuity ? <div><dt>Acuity</dt><dd>{props.triage.acuity}</dd></div> : null}
                  {props.triage.consciousness ? <div><dt>Consciousness</dt><dd>{props.triage.consciousness}</dd></div> : null}
                </dl>
                <p className="clinician-context__meta">Assessed {formatDate(props.triage.occurredAt, true)}</p>
              </>
            ) : (
              <p className="clinician-context__empty">No triage assessment is linked to this encounter.</p>
            )}
            <div className="clinician-nursing-notes">
              <h3 className="clinician-panel__subtitle">Nursing notes</h3>
              {nursingNotes.length === 0 ? (
                <p className="clinician-context__empty">No nursing notes are recorded for this encounter.</p>
              ) : nursingNotes.slice(0, 2).map((note) => (
                <article key={note.clinicalNoteId} className="clinician-nursing-note">
                  <strong>{note.title ?? note.noteType.replaceAll('_', ' ')}</strong>
                  <p>{truncate(note.body, 180)}</p>
                  <span>{formatDate(note.occurredAt, true)}</span>
                </article>
              ))}
            </div>
          </section>

        </aside>

        <div className="clinical-entry-workspace__workbench clinician-workspace__workbench">
          {active ? (
            <section
              className="clinical-entry-workspace-navigation clinician-workspace-navigation"
              aria-label="Clinical encounter workspace"
            >
              <TabsVertical
                selectedIndex={WORKSPACE_TABS.indexOf(selectedWorkspaceTab)}
                onChange={({ selectedIndex }) => selectWorkspaceTab(WORKSPACE_TABS[selectedIndex] ?? 'documentation')}
              >
                <TabListVertical
                  aria-label="Clinical encounter workspace"
                  className="clinical-entry-workspace__vertical-tabs clinician-workspace__vertical-tabs"
                  size="md"
                >
                  <Tab renderIcon={Document}>Document</Tab>
                  <Tab renderIcon={Medication}>{`Order Basket${basketEntries.length + labBasketEntries.length > 0 ? ` (${basketEntries.length + labBasketEntries.length})` : ''}`}</Tab>
                  <Tab renderIcon={Task}>Plan &amp; finish</Tab>
                  <Tab renderIcon={Report}>{`Visit record${encounterRecordCount > 0 ? ` (${encounterRecordCount})` : ''}`}</Tab>
                </TabListVertical>
                <TabPanels>
                  {WORKSPACE_TABS.map((tab) => (
                    <TabPanel key={tab}>
                      {selectedWorkspaceTab === tab ? (
                        <div className="clinical-entry-tab-content clinician-workspace__tab-content">
          <div className="clinician-workspace__form-content">
              {encounterRecordCount > 0 ? (
                <details id="clinician-recorded" className="afy-card clinician-panel clinician-recorded">
                  <summary className="clinician-recorded__summary">
                    <span>Recorded this visit</span>
                    <Tag size="sm" type="blue">{encounterRecordCount}</Tag>
                  </summary>
                  <div className="clinician-recorded__body">
                    {props.diagnoses.length > 0 ? (
                      <section className="clinician-recorded__section">
                        <h3 className="clinician-panel__subtitle">Diagnoses</h3>
                        <ul className="clinician-recorded__list">
                          {props.diagnoses.map((diagnosis) => (
                            <li key={diagnosis.diagnosisId}>
                              <span>
                                <strong>{diagnosis.description}</strong>
                                {diagnosis.code ? ` · ${diagnosis.code}` : ''}
                              </span>
                              <span className="clinician-recorded__meta">
                                <Tag size="sm" type="blue">{diagnosis.clinicalStatus}</Tag>
                                {formatDate(diagnosis.occurredAt, true)}
                              </span>
                            </li>
                          ))}
                        </ul>
                      </section>
                    ) : null}
                    {props.plans.length > 0 ? (
                      <section className="clinician-recorded__section">
                        <h3 className="clinician-panel__subtitle">Plans</h3>
                        <ul className="clinician-recorded__list">
                          {props.plans.map((plan) => (
                            <li key={plan.planId}>
                              <span>
                                <Tag size="sm" type={plan.status === 'ACTIVE' ? 'green' : 'gray'}>{plan.planType.replaceAll('_', ' ')}</Tag>
                                {plan.clinicalIntent}
                              </span>
                              <span className="clinician-recorded__meta">{formatDate(plan.occurredAt, true)}</span>
                            </li>
                          ))}
                        </ul>
                      </section>
                    ) : null}
                    {props.notes.length > 0 ? (
                      <section className="clinician-recorded__section">
                        <h3 className="clinician-panel__subtitle">Notes</h3>
                        {noteActionError ? (
                          <InlineNotification kind="error" title={noteActionError} lowContrast />
                        ) : null}
                        <ul className="clinician-recorded__list">
                          {props.notes.slice(0, 5).map((note) => (
                            <li key={note.clinicalNoteId}>
                              <span>
                                <strong>{note.title ?? note.noteType.replaceAll('_', ' ')}</strong>
                                <Tag size="sm" type={note.status === 'SIGNED' ? 'green' : 'blue'}>{note.status}</Tag>
                                {note.amendmentCount > 0 ? (
                                  <Tag size="sm" type="purple">Amended · {note.amendmentCount}</Tag>
                                ) : null}
                                <span className="clinician-recorded__preview">{truncate(note.body, 220)}</span>
                              </span>
                              <span>
                                <span className="clinician-recorded__meta">{formatDate(note.occurredAt, true)}</span>
                                {note.signedAt ? (
                                  <span className="clinician-recorded__meta">Signed {formatDate(note.signedAt, true)}</span>
                                ) : null}
                                {props.canSignNotes && note.status === 'DRAFT' ? (
                                  <Button
                                    kind="ghost"
                                    size="sm"
                                    disabled={signingNote}
                                    onClick={() => requestNoteSignature(note.clinicalNoteId)}
                                  >
                                    Sign note
                                  </Button>
                                ) : null}
                                {props.canAmendNotes && note.status === 'SIGNED' ? (
                                  <Button
                                    kind="ghost"
                                    size="sm"
                                    disabled={amendmentSubmitting || amendmentNoteId !== null}
                                    onClick={() => openAmendmentPanel(note.clinicalNoteId)}
                                  >
                                    Amend note
                                  </Button>
                                ) : null}
                              </span>
                            </li>
                          ))}
                        </ul>
                      </section>
                    ) : null}
                    {props.canReadPrescribing ? (
                      <section className="clinician-recorded__section">
                        <h3 className="clinician-panel__subtitle">Medication orders</h3>
                        {props.prescribingDecisions.length === 0 ? (
                          <p className="clinician-context__empty">No medication decisions are recorded for this encounter.</p>
                        ) : (
                          <ul className="clinician-recorded__list">
                            {props.prescribingDecisions.map((decision) => (
                              <li key={decision.prescribingDecisionId}>
                                <span>
                                  <strong>{decision.medicationName}</strong>
                                  {decision.dose ? ` · ${decision.dose}` : ''}
                                  {decision.route ? `, ${decision.route}` : ''}
                                  {decision.frequency ? `, ${decision.frequency}` : ''}
                                </span>
                                <Tag size="sm" type="blue">{decision.status}</Tag>
                              </li>
                            ))}
                          </ul>
                        )}
                      </section>
                    ) : null}
                    {props.procedures.length > 0 ? (
                      <section className="clinician-recorded__section">
                        <h3 className="clinician-panel__subtitle">Procedure orders</h3>
                        <ul className="clinician-recorded__list">
                          {props.procedures.map((procedure) => {
                            const definition = props.procedureDefinitions.find(
                              (item) => item.definitionId === procedure.definitionId,
                            );

                            return (
                              <li key={procedure.procedureId}>
                                <span>
                                  <strong>{definition?.name ?? 'Procedure'}</strong>
                                  {procedure.indication ? ` · ${procedure.indication}` : ''}
                                </span>
                                <Tag size="sm" type={procedure.status === 'AUTHORIZED' ? 'green' : 'blue'}>
                                  {procedure.status.replaceAll('_', ' ')}
                                </Tag>
                              </li>
                            );
                          })}
                        </ul>
                      </section>
                    ) : null}
                    <section className="clinician-recorded__section">
                      <h3 className="clinician-panel__subtitle">Results</h3>
                      <p className="clinician-context__empty">Laboratory results remain in the patient chart to preserve the existing results workflow.</p>
                      <Link className="clinician-safety__link" href={routes.patient.chart(props.patient.patientId, 'lab-results')}>View laboratory results</Link>
                    </section>
                  </div>
                </details>
              ) : null}
              {encounterRecordCount === 0 ? (
                <section id="clinician-recorded" className="afy-card clinician-panel clinician-recorded clinician-recorded--empty" aria-labelledby="clinician-recorded-heading">
                  <h2 id="clinician-recorded-heading" className="clinician-panel__title">Encounter record</h2>
                  <p className="clinician-panel__description">No documentation or orders have been recorded for this encounter yet.</p>
                  <Link className="clinician-safety__link" href={routes.patient.chart(props.patient.patientId, 'lab-results')}>View laboratory results in the patient chart</Link>
                </section>
              ) : null}
              <div className="clinician-documentation-group">
                {props.canRecordNote ? (
                  <section
                    id="clinician-note"
                    aria-labelledby="clinician-note-heading"
                    className="afy-card clinician-panel clinician-documentation-section clinician-documentation-section--visit-note"
                  >
                    <div className="clinician-note__header">
                      <div className="clinician-section-heading">
                        <h2 id="clinician-note-heading" className="clinician-panel__title">Visit note</h2>
                        {noteDraft && !discardedDraftSections.includes('note') ? <Tag size="sm" type="purple">Restored draft</Tag> : null}
                      </div>
                    </div>
                    <p className="clinician-panel__description">Write the consultation note here. Stage medication and lab orders in the Order Basket when needed.</p>
                    <FormErrors errors={noteForm.errors} fields={['patient_id', 'note_type', 'body']} />
                    {discardError?.section === 'note' ? (
                      <InlineNotification kind="error" title={discardError.message} lowContrast />
                    ) : null}
                    <Form onSubmit={createNote}>
                      <TextArea
                        id="clinician-note-body"
                        labelText="Consultation note"
                        rows={5}
                        value={noteForm.data.body}
                        onChange={(event) => noteForm.setData('body', event.target.value)}
                        invalid={Boolean(noteForm.errors.body)}
                        invalidText={noteForm.errors.body}
                        required
                      />
                      <div className="clinician-draft-actions">
                        <Button className="clinician-form__submit" size="md" renderIcon={Checkmark} type="submit" disabled={noteForm.processing}>
                          {noteForm.processing ? 'Saving…' : 'Save note draft'}
                        </Button>
                        {(noteDraft && !discardedDraftSections.includes('note')) || noteHasContent || noteDocumentation.savedAt ? (
                          <>
                            <Button
                              kind="danger--ghost"
                              size="md"
                              type="button"
                              disabled={!isOnline || discardingDraft}
                              onClick={() => {
                                setDiscardError(null);
                                setDiscardTarget('note');
                              }}
                            >
                              Discard draft
                            </Button>
                            {!isOnline ? <span role="status">Reconnect to discard this draft.</span> : null}
                          </>
                        ) : null}
                      </div>
                    </Form>
                  </section>
                ) : null}

                {props.canRecordDiagnosis ? (
                  <section
                    id="clinician-diagnosis"
                    className="afy-card clinician-panel clinician-documentation-section"
                  >
                    <div className="clinician-section-heading">
                      <h2 className="clinician-panel__title">Encounter diagnosis</h2>
                      <Tag size="sm" type="cool-gray">Optional</Tag>
                      {diagnosisDraft && !discardedDraftSections.includes('diagnosis') ? <Tag size="sm" type="purple">Restored draft</Tag> : null}
                    </div>
                    <p className="clinician-panel__description">Coding is optional and accepted as an open code; AfyaNova does not infer diagnoses or validate against an unconfigured terminology.</p>
                    <FormErrors errors={diagnosisForm.errors} fields={['patient_id', 'description', 'code', 'code_system', 'clinical_status']} />
                    {discardError?.section === 'diagnosis' ? (
                      <InlineNotification kind="error" title={discardError.message} lowContrast />
                    ) : null}
                    <Form onSubmit={recordDiagnosis}>
                      <SearchableComboBox
                        id="diagnosis-catalog-search"
                        titleText="Search diagnosis catalogue"
                        items={diagnosisItems}
                        selectedId={selectedDiagnosisId}
                        helperText="Search by diagnosis name, code, alias, or category."
                        size="md"
                        onChange={(selectedId) => {
                          const selected = diagnosisCatalog.find(
                            (diagnosis) => `${diagnosis.codeSystem}:${diagnosis.code}` === selectedId,
                          );

                          if (selected) {
                            diagnosisForm.setData('description', selected.name);
                            diagnosisForm.setData('code', selected.code);
                            diagnosisForm.setData('code_system', selected.codeSystem);
                          } else {
                            diagnosisForm.setData('code', '');
                            diagnosisForm.setData('code_system', '');
                          }
                        }}
                      />
                      <div className="clinician-diagnosis__fields">
                        <TextInput
                          id="diagnosis-description"
                          labelText="Clinical description"
                          value={diagnosisForm.data.description}
                          onChange={(event) => diagnosisForm.setData('description', event.target.value)}
                          invalid={Boolean(diagnosisForm.errors.description)}
                          invalidText={diagnosisForm.errors.description}
                          required
                        />
                        <TextInput
                          id="diagnosis-code"
                          labelText="Code (optional)"
                          value={diagnosisForm.data.code}
                          onChange={(event) => diagnosisForm.setData('code', event.target.value)}
                          invalid={Boolean(diagnosisForm.errors.code)}
                          invalidText={diagnosisForm.errors.code}
                        />
                        <TextInput
                          id="diagnosis-code-system"
                          labelText="Code system"
                          value={diagnosisForm.data.code_system}
                          onChange={(event) => diagnosisForm.setData('code_system', event.target.value)}
                          invalid={Boolean(diagnosisForm.errors.code_system)}
                          invalidText={diagnosisForm.errors.code_system}
                        />
                      </div>
                      <div className="clinician-draft-actions">
                        <Button className="clinician-form__submit" size="md" renderIcon={Checkmark} type="submit" disabled={diagnosisForm.processing}>
                          {diagnosisForm.processing ? 'Saving…' : 'Record diagnosis'}
                        </Button>
                        {(diagnosisDraft && !discardedDraftSections.includes('diagnosis')) || diagnosisHasContent || diagnosisDocumentation.savedAt ? (
                          <>
                            <Button
                              kind="danger--ghost"
                              size="md"
                              type="button"
                              disabled={!isOnline || discardingDraft}
                              onClick={() => {
                                setDiscardError(null);
                                setDiscardTarget('diagnosis');
                              }}
                            >
                              Discard draft
                            </Button>
                            {!isOnline ? <span role="status">Reconnect to discard this draft.</span> : null}
                          </>
                        ) : null}
                      </div>
                    </Form>
                  </section>
                ) : null}

              </div>
              <div className="clinician-documentation-group clinician-documentation-group--additional">
                {props.canRecordPlan ? (
                  <section
                    id="clinician-plan"
                    className="afy-card clinician-panel clinician-documentation-section"
                  >
                    <div className="clinician-section-heading">
                      <h2 className="clinician-panel__title">Clinical plan</h2>
                      <Tag size="sm" type="cool-gray">Optional</Tag>
                      {planDraft && !discardedDraftSections.includes('plan') ? <Tag size="sm" type="purple">Restored draft</Tag> : null}
                    </div>
                    <p className="clinician-panel__description">Record the clinical plan in one place. Include follow-up or instructions here when needed.</p>
                    <FormErrors errors={planForm.errors} fields={['patient_id', 'plan_type', 'clinical_intent']} />
                    {discardError?.section === 'plan' ? (
                      <InlineNotification kind="error" title={discardError.message} lowContrast />
                    ) : null}
                    <Form onSubmit={createPlan}>
                      <TextArea
                        id="clinical-plan-intent"
                        labelText="Clinical plan"
                        rows={6}
                        value={planForm.data.clinical_intent}
                        onChange={(event) => planForm.setData('clinical_intent', event.target.value)}
                        invalid={Boolean(planForm.errors.clinical_intent)}
                        invalidText={planForm.errors.clinical_intent}
                        required
                      />
                      <div className="clinician-draft-actions">
                        <Button className="clinician-form__submit" size="md" renderIcon={Checkmark} type="submit" disabled={planForm.processing}>
                          {planForm.processing ? 'Saving…' : 'Save clinical plan'}
                        </Button>
                        {(planDraft && !discardedDraftSections.includes('plan')) || planHasContent || planDocumentation.savedAt ? (
                          <>
                            <Button
                              kind="danger--ghost"
                              size="md"
                              type="button"
                              disabled={!isOnline || discardingDraft}
                              onClick={() => {
                                setDiscardError(null);
                                setDiscardTarget('plan');
                              }}
                            >
                              Discard draft
                            </Button>
                            {!isOnline ? <span role="status">Reconnect to discard this draft.</span> : null}
                          </>
                        ) : null}
                      </div>
                    </Form>
                  </section>
                ) : null}
              </div>

              {props.canChangeDisposition || props.canCompleteEncounter ? (
                <section id="clinician-disposition" className="afy-card clinician-panel clinician-disposition">
                  <h2 className="clinician-panel__title">Disposition and completion</h2>
                  <p className="clinician-panel__description">Choose and save the encounter outcome before completing the visit.</p>
                  {props.canChangeDisposition ? (
                    <FormErrors errors={dispositionForm.errors} fields={['disposition']} />
                  ) : null}
                  <div className="clinician-disposition__actions">
                    {props.canChangeDisposition ? (
                      <Form onSubmit={saveDisposition}>
                        <Select
                          id="encounter-disposition"
                          labelText="Disposition"
                          value={dispositionForm.data.disposition}
                          onChange={(event) => {
                            dispositionForm.setData('disposition', event.currentTarget.value);
                            setDispositionSaved(false);
                          }}
                          invalid={Boolean(dispositionForm.errors.disposition)}
                          invalidText={dispositionForm.errors.disposition}
                        >
                          {['DISCHARGED', 'REFERRED', 'LEFT', 'FOLLOW_UP', 'DECEASED', 'OTHER'].map((value) => (
                            <SelectItem key={value} value={value} text={value.replaceAll('_', ' ')} />
                          ))}
                        </Select>
                        <div className="clinician-disposition__buttons">
                          <Button className="clinician-form__submit" size="lg" renderIcon={Checkmark} type="submit" kind="secondary" disabled={dispositionForm.processing}>
                            Save disposition
                          </Button>
                          {props.canCompleteEncounter ? (
                            <Button
                              size="lg"
                              renderIcon={Checkmark}
                              type="button"
                              onClick={() => setCompleteDialogOpen(true)}
                              disabled={!dispositionSaved || completing}
                            >
                              {completing ? 'Completing…' : 'Complete visit'}
                            </Button>
                          ) : null}
                        </div>
                      </Form>
                    ) : props.canCompleteEncounter ? (
                      <Button
                        size="lg"
                        renderIcon={Checkmark}
                        onClick={() => setCompleteDialogOpen(true)}
                        disabled={!dispositionSaved || completing}
                      >
                        {completing ? 'Completing…' : 'Complete visit'}
                      </Button>
                    ) : null}
                  </div>
                  {!dispositionSaved && props.canCompleteEncounter
                    ? <p>Save a disposition in this session before completing.</p>
                    : null}
                </section>
              ) : null}

          </div>
        {selectedWorkspaceTab === 'orders' && (canAccessOrderBasket || props.canRequestProcedure) ? (
            <section
              className="clinician-workspace__orders"
              role="region"
              aria-label="Order Basket"
            >
              <header className="clinician-orders__header">
                <h2 id="clinician-orders-heading" className="clinician-panel__title">Order basket</h2>
                <p className="clinician-orders__description">Manage medication and laboratory orders for this encounter.</p>
              </header>
              {canAccessDrugOrders ? (
                <section
                  className="clinician-order-group clinician-order-group--drug"
                  role="region"
                  aria-label="Drug orders"
                >
                  <div className="clinician-orders__section-heading">
                    <div className="clinician-orders__title">
                      <span className="clinician-orders__icon" aria-hidden="true"><Medication size={20} /></span>
                      <h3 id="clinician-drug-orders-heading" className="clinician-panel__title">Drug orders ({basketEntries.length})</h3>
                  </div>
                  {props.canRecordPrescribing ? (
                    <Button
                      type="button"
                      kind="tertiary"
                      size="sm"
                      renderIcon={Add}
                      aria-label="Add drug orders"
                      disabled={basketSigning || hasUncertainBasketEntry}
                      onClick={() => openOrderPicker('drug')}
                    >
                      Add
                    </Button>
                  ) : null}
                  <IconButton
                    kind="ghost"
                    size="sm"
                    label={drugOrdersExpanded ? 'Hide drug orders' : 'View drug orders'}
                    wrapperClasses="clinician-orders__toggle"
                    aria-expanded={drugOrdersExpanded}
                    aria-controls="clinician-drug-orders-content"
                    disabled={basketEntries.length === 0}
                    onClick={() => setDrugOrdersExpanded((expanded) => !expanded)}
                  >
                    <ChevronDown />
                  </IconButton>
                </div>
                <div
                  id="clinician-drug-orders-content"
                  className="clinician-order-group__content"
                  hidden={!drugOrdersExpanded}
                >
                <Form onSubmit={signMedicationBasket} noValidate>
                  {basketError ? (
                    <InlineNotification kind="error" title={basketError} lowContrast />
                  ) : null}
            {hasUncertainBasketEntry ? (
              <InlineNotification
                kind="warning"
                title="An order's outcome is not confirmed. Retry this unchanged order before editing it."
                lowContrast
              />
            ) : null}
            {basketEntries.length > 0 ? (
              <div className="clinician-medication-basket" aria-label="Staged drug orders">
                <ul className="clinician-medication-basket__list">
                  {basketEntries.map((entry) => {
                    const duration = medicationDuration(entry);
                    const isComplete = isMedicationOrderComplete(entry);
                    const entryStatusClass = entry.outcomeUncertain
                      ? 'clinician-medication-basket__entry--uncertain'
                      : isComplete
                        ? 'clinician-medication-basket__entry--complete'
                        : 'clinician-medication-basket__entry--incomplete';

                    return (
                      <li key={entry.basketId} className={entryStatusClass}>
                        <div className="clinician-medication-basket__heading">
                          <strong>{entry.name}</strong>
                          <span>Code: {entry.code} · Unit: {entry.unitOfMeasure}</span>
                          <Tag size="sm" type={isComplete ? 'green' : 'warm-gray'}>
                            {isComplete ? 'Complete' : 'Incomplete'}
                          </Tag>
                          {entry.outcomeUncertain ? <Tag size="sm" type="red">Outcome unconfirmed</Tag> : null}
                          {entry.dose || entry.route || entry.frequency || duration ? (
                            <div className="clinician-medication-basket__sig">
                              <span className="clinician-medication-basket__sig-text">
                                <strong>Sig:</strong> {[entry.dose, entry.route, entry.frequency, duration ? `for ${duration}` : ''].filter(Boolean).join(' · ')}
                                {entry.quantityValue ? ` (Qty: ${entry.quantityValue})` : ''}
                              </span>
                              {entry.indication ? (
                                <span className="clinician-medication-basket__sig-indication">
                                  Indication: {entry.indication}
                                </span>
                              ) : null}
                              {entry.dosingInstruction ? (
                                <span className="clinician-medication-basket__sig-indication">
                                  Instructions: {entry.dosingInstruction}
                                </span>
                              ) : null}
                            </div>
                          ) : null}
                        </div>
                        <div className="clinician-medication-basket__actions">
                          <Button
                            type="button"
                            kind="ghost"
                            size="sm"
                            renderIcon={Edit}
                            disabled={basketSigning || hasUncertainBasketEntry}
                            onClick={() => openStagedMedicationOrderForm(entry.basketId)}
                          >
                            Order form
                          </Button>
                          <Button
                            type="button"
                            kind="danger--ghost"
                            size="sm"
                            renderIcon={TrashCan}
                            disabled={basketSigning || hasUncertainBasketEntry}
                            onClick={() => removeMedicationFromBasket(entry.basketId)}
                          >
                            Remove
                          </Button>
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </div>
            ) : (
              <div className="clinician-order-group__empty">
                <span>No drug orders staged in the basket. Click &ldquo;Add +&rdquo; above to search and stage medications.</span>
              </div>
            )}
                  {props.canRecordPrescribing ? (
                    <Button
                      type="submit"
                      size="lg"
                      disabled={!basketComplete || basketSigning}
                    >
                      {basketProgress
                        ? `Signing ${Math.min(basketProgress.done + 1, basketProgress.total)} of ${basketProgress.total}…`
                        : basketSigning
                          ? 'Signing…'
                          : 'Sign prescriptions'}
                    </Button>
                  ) : null}
                </Form>
                </div>
              </section>
            ) : null}
            {props.canRecordLabOrders ? (
              <section
                className="clinician-order-group clinician-order-group--lab"
                role="region"
                aria-label="Lab orders"
              >
                <div className="clinician-orders__section-heading">
                  <div className="clinician-orders__title">
                    <span className="clinician-orders__icon" aria-hidden="true"><Microscope size={20} /></span>
                    <h3 id="clinician-lab-orders-heading" className="clinician-panel__title">Lab orders ({labBasketEntries.length})</h3>
                  </div>
                  <Button
                    type="button"
                    kind="tertiary"
                    size="sm"
                    renderIcon={Add}
                    aria-label="Add lab orders"
                    disabled={labSubmitting || labOrderAttempt !== null}
                    onClick={() => openOrderPicker('lab')}
                  >
                    Add
                  </Button>
                  <IconButton
                    kind="ghost"
                    size="sm"
                    label={labOrdersExpanded ? 'Hide lab orders' : 'View lab orders'}
                    wrapperClasses="clinician-orders__toggle"
                    aria-expanded={labOrdersExpanded}
                    aria-controls="clinician-lab-orders-content"
                    disabled={labBasketEntries.length === 0}
                    onClick={() => setLabOrdersExpanded((expanded) => !expanded)}
                  >
                    <ChevronDown />
                  </IconButton>
                </div>
                {labOrderError ? (
                  <InlineNotification kind="error" title={labOrderError} lowContrast />
                ) : null}
                {labOrderSuccess ? (
                  <InlineNotification kind="success" title={labOrderSuccess} lowContrast />
                ) : null}
                <div
                  id="clinician-lab-orders-content"
                  className="clinician-order-group__content"
                  hidden={!labOrdersExpanded}
                >
                <Form onSubmit={submitLabOrders} noValidate>
                  {labBasketEntries.length > 0 ? (
                    <>
                      <div className="clinician-lab-basket" aria-label="Staged lab orders">
                        <ul className="clinician-lab-basket__list">
                          {labBasketEntries.map((entry) => (
                            <li key={`${entry.type}-${entry.id}`}>
                              <span>
                                <strong>{entry.name}</strong>
                                <span>{entry.code}{entry.type === 'panel' ? ' · Panel' : ''}</span>
                                {entry.type === 'panel' ? <Tag size="sm" type="purple">Panel</Tag> : null}
                              </span>
                              <Button
                                type="button"
                                kind="danger--ghost"
                                size="sm"
                                disabled={labSubmitting || labOrderAttempt !== null}
                                onClick={() => removeLabEntry(entry)}
                              >
                                Remove
                              </Button>
                            </li>
                          ))}
                        </ul>
                      </div>
                      <Select
                        id="clinician-lab-priority"
                        labelText="Priority"
                        value={labPriority}
                        onChange={(event) => setLabPriority(event.currentTarget.value)}
                        disabled={labSubmitting || labOrderAttempt !== null}
                      >
                        <SelectItem value="ROUTINE" text="Routine" />
                        <SelectItem value="URGENT" text="Urgent" />
                      </Select>
                      <TextArea
                        id="clinician-lab-indication"
                        labelText="Clinical indication (optional)"
                        rows={3}
                        maxLength={1000}
                        value={labClinicalIndication}
                        onChange={(event) => setLabClinicalIndication(event.target.value)}
                        disabled={labSubmitting || labOrderAttempt !== null}
                      />
                    </>
                  ) : (
                    <div className="clinician-order-group__empty">
                      <span>No laboratory tests or panels staged. Click &ldquo;Add +&rdquo; above to search available investigations.</span>
                    </div>
                  )}
                  <Button
                    type="submit"
                    size="lg"
                    disabled={labSubmitting || labBasketEntries.length === 0}
                  >
                    {labSubmitting
                      ? 'Submitting…'
                      : labOrderAttempt !== null && labOrderError
                        ? 'Retry lab order'
                        : 'Submit lab order'}
                  </Button>
                </Form>
                </div>
              </section>
            ) : null}
            {props.canReadPrescribing ? (
              <section
                className="afy-card clinician-panel clinician-documentation-section clinician-medication-history"
                aria-labelledby="medication-history-heading"
              >
                <h2 id="medication-history-heading" className="clinician-panel__title">Medication decisions this visit</h2>
                {props.prescribingDecisions.length === 0 ? (
                  <p>No medication decisions recorded for this encounter.</p>
                ) : (
                  <ul className="clinician-medication-history__list">
                    {props.prescribingDecisions.map((decision) => (
                      <li key={decision.prescribingDecisionId} className="clinician-medication-history__item">
                        <div className="clinician-medication-history__details">
                          <strong>{decision.medicationName}</strong>
                          {(decision.dose || decision.route || decision.frequency) ? (
                            <span className="clinician-medication-history__sig">
                              {decision.dose ? ` — ${decision.dose}` : ''}
                              {decision.route ? `, ${decision.route}` : ''}
                              {decision.frequency ? `, ${decision.frequency}` : ''}
                            </span>
                          ) : null}
                        </div>
                        <Tag size="sm" type="blue">{decision.status}</Tag>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            ) : null}
            {props.canRequestProcedure ? (
              <details
                id="clinician-procedure"
                className="afy-card clinician-panel clinician-optional-section clinician-orders__procedures"
                open={Boolean(
                  procedureActionError
                  || Object.keys(procedureForm.errors).length > 0
                  || props.procedures.some((procedure) => procedure.status === 'REQUESTED')
                )}
              >
                <summary className="clinician-optional-section__summary">
                  <span className="clinician-panel__title">Procedure orders</span>
                  {props.procedures.length > 0 ? <Tag size="sm" type="blue">{props.procedures.length} recorded</Tag> : null}
                </summary>
                <p className="clinician-panel__description">
                  Request the procedure, then authorize it. Authorization automatically routes the order to the configured procedure queue.
                </p>
                <FormErrors errors={procedureForm.errors} fields={['patient_id', 'definition_id', 'indication']} />
                {procedureActionError ? (
                  <InlineNotification kind="error" title={procedureActionError} lowContrast />
                ) : null}
                {props.procedureDefinitions.length === 0 ? (
                  <p>No active procedure definitions are available.</p>
                ) : (
                  <Form onSubmit={requestProcedure}>
                    <Select
                      id="procedure-definition"
                      labelText="Procedure"
                      value={procedureForm.data.definition_id}
                      onChange={(event) => procedureForm.setData('definition_id', event.currentTarget.value)}
                      invalid={Boolean(procedureForm.errors.definition_id)}
                      invalidText={procedureForm.errors.definition_id}
                    >
                      <SelectItem value="" text="Select a procedure" />
                      {props.procedureDefinitions.map((definition) => (
                        <SelectItem
                          key={definition.definitionId}
                          value={definition.definitionId}
                          text={`${definition.name} (${definition.code})`}
                        />
                      ))}
                    </Select>
                    <TextArea
                      id="procedure-indication"
                      labelText="Indication (optional)"
                      rows={3}
                      value={procedureForm.data.indication}
                      onChange={(event) => procedureForm.setData('indication', event.target.value)}
                      invalid={Boolean(procedureForm.errors.indication)}
                      invalidText={procedureForm.errors.indication}
                    />
                    <Button size="lg" renderIcon={HealthCross}
                      type="submit"
                      disabled={procedureForm.processing || procedureForm.data.definition_id === ''}
                      className="clinician-form__submit"
                    >
                      {procedureForm.processing ? 'Requesting…' : 'Request procedure'}
                    </Button>
                  </Form>
                )}
                <div className="clinician-procedure-list">
                  {props.procedures.length === 0 ? <p>No procedure orders are recorded for this encounter.</p> : (
                    props.procedures.map((procedure) => {
                      const definition = props.procedureDefinitions.find(
                        (item) => item.definitionId === procedure.definitionId,
                      );
                      const queueConfigured = definition?.queueConfigured === true;

                      return (
                        <div
                          key={procedure.procedureId}
                          className="clinician-procedure"
                        >
                          <div className="clinician-procedure__summary">
                            <strong>{procedure.procedureNumber}</strong>
                            {' · '}
                            {definition?.name ?? 'Procedure'}
                            {' · '}
                            <Tag type={procedure.status === 'AUTHORIZED' ? 'green' : 'blue'}>
                              {procedure.status.replaceAll('_', ' ')}
                            </Tag>
                            {procedure.authorizationBasis === 'APPROVED_PROTOCOL' ? (
                              <Tag type="teal">
                                Protocol · {procedure.authorizationProtocolReference}
                              </Tag>
                            ) : null}
                          </div>
                          {procedure.indication ? <p className="clinician-procedure__indication">{procedure.indication}</p> : null}
                          {procedure.status === 'REQUESTED' ? (
                            <>
                              {!queueConfigured ? (
                                <p role="status">
                                  Configure an active procedure queue with an active room before authorizing this order.
                                </p>
                              ) : null}
                              <Button
                                kind="secondary"
                                size="sm"
                                disabled={!queueConfigured || authorizingProcedureId !== null}
                                onClick={() => authorizeProcedure(procedure.procedureId)}
                              >
                                {authorizingProcedureId === procedure.procedureId ? 'Authorizing…' : 'Authorize and route'}
                              </Button>
                            </>
                          ) : null}
                        </div>
                      );
                    })
                  )}
                </div>
              </details>
            ) : null}
          </section>
        ) : null}
        </div>
                      ) : null}
                    </TabPanel>
                  ))}
                </TabPanels>
              </TabsVertical>
            </section>
          ) : (
            <div className="clinician-workspace__main">
              <section id="clinician-start" className="afy-card clinician-panel">
                <h2 className="clinician-panel__title">Begin consultation</h2>
                <p className="clinician-panel__description">Start the encounter to document the visit, add orders, and create a care plan.</p>
                {props.canStartEncounter && props.encounter.status === 'ARRIVED' ? (
                  <Button size="lg" renderIcon={Play} onClick={startEncounter}>Start encounter</Button>
                ) : <p className="clinician-panel__note">Documentation is available after the encounter has been started.</p>}
              </section>
            </div>
          )}
        {activeOrderPicker ? (
          <DockedPanel
            heading={activeOrderPicker === 'drug'
              ? (activeMedicationFormEntry ? 'Medication order form' : 'Add drug orders')
              : 'Add lab orders'}
            ariaLabel={activeOrderPicker === 'drug'
              ? (activeMedicationFormEntry ? 'Medication order form' : 'Add drug orders')
              : 'Add lab orders'}
            description={activeOrderPicker === 'drug'
              ? (activeMedicationFormEntry
                ? `${activeMedicationFormEntry.name} · ${activeMedicationFormEntry.code}`
                : 'Search the medication catalog and add medicines to this encounter.')
              : 'Search available laboratory tests and panels for this facility.'}
            placement="right"
              width={activeOrderPicker === 'drug'
                ? 'min(30rem, calc(100vw - 2rem))'
                : 'min(38rem, calc(100vw - 2rem))'}
              offsetInlineEnd="1rem"
              onClose={closeOrderPicker}
              contentClassName={`clinician-order-picker${activeOrderPicker === 'drug' && !activeMedicationFormEntry
                ? ' clinician-order-picker--drug-search'
                : ''}`}
          >
            {activeOrderPicker === 'drug' ? (
              activeMedicationFormEntry ? (
                <>
                  <Button
                    type="button"
                    kind="ghost"
                    size="sm"
                    onClick={() => setActiveMedicationFormBasketId(null)}
                  >
                    Back to drug search
                  </Button>
                  <div className="clinician-medication-basket__form">
                    <div className="clinician-medication-basket__identity">
                      <strong>{activeMedicationFormEntry.name}</strong>
                      <span>
                        Code: {activeMedicationFormEntry.code} · Stock unit: {activeMedicationFormEntry.unitOfMeasure}
                      </span>
                    </div>
                    <section className="clinician-medication-basket__section" aria-labelledby="clinician-medication-directions">
                      <h3 id="clinician-medication-directions">Dosage and schedule</h3>
                      <div className="clinician-medication-basket__fields">
                        <TextInput
                          id={`basket-dose-${activeMedicationFormEntry.basketId}`}
                          labelText="Dose"
                          helperText="Include the dose unit, e.g. 1 capsule or 5 mL."
                          maxLength={500}
                          value={activeMedicationFormEntry.dose}
                          onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'dose', event.target.value)}
                          disabled={basketSigning || hasUncertainBasketEntry}
                          required
                        />
                        <div>
                          <Select
                            id={`basket-route-${activeMedicationFormEntry.basketId}`}
                            labelText="Route"
                            value={activeMedicationFormEntry.routeCustom ? OTHER_MEDICATION_OPTION : activeMedicationFormEntry.route}
                            onChange={(event) => {
                              const isCustom = event.target.value === OTHER_MEDICATION_OPTION;
                              updateBasketEntry(activeMedicationFormEntry.basketId, 'routeCustom', isCustom);
                              updateBasketEntry(activeMedicationFormEntry.basketId, 'route', isCustom ? '' : event.target.value);
                            }}
                            disabled={basketSigning || hasUncertainBasketEntry}
                            required
                          >
                            <SelectItem value="" text="Select a route" />
                            {MEDICATION_ROUTE_OPTIONS.map((route) => (
                              <SelectItem key={route} value={route} text={route} />
                            ))}
                            <SelectItem value={OTHER_MEDICATION_OPTION} text={OTHER_MEDICATION_OPTION} />
                          </Select>
                          {activeMedicationFormEntry.routeCustom ? (
                            <TextInput
                              id={`basket-route-custom-${activeMedicationFormEntry.basketId}`}
                              labelText="Custom route"
                              maxLength={100}
                              value={activeMedicationFormEntry.route}
                              onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'route', event.target.value)}
                              disabled={basketSigning || hasUncertainBasketEntry}
                              required
                            />
                          ) : null}
                        </div>
                        <div>
                          <Select
                            id={`basket-frequency-${activeMedicationFormEntry.basketId}`}
                            labelText="Frequency"
                            value={activeMedicationFormEntry.frequencyCustom
                              ? OTHER_MEDICATION_OPTION
                              : activeMedicationFormEntry.frequency}
                            onChange={(event) => {
                              const isCustom = event.target.value === OTHER_MEDICATION_OPTION;
                              updateBasketEntry(activeMedicationFormEntry.basketId, 'frequencyCustom', isCustom);
                              updateBasketEntry(activeMedicationFormEntry.basketId, 'frequency', isCustom ? '' : event.target.value);
                            }}
                            disabled={basketSigning || hasUncertainBasketEntry}
                            required
                          >
                            <SelectItem value="" text="Select a frequency" />
                            {MEDICATION_FREQUENCY_OPTIONS.map((frequency) => (
                              <SelectItem key={frequency} value={frequency} text={frequency} />
                            ))}
                            <SelectItem value={OTHER_MEDICATION_OPTION} text={OTHER_MEDICATION_OPTION} />
                          </Select>
                          {activeMedicationFormEntry.frequencyCustom ? (
                            <TextInput
                              id={`basket-frequency-custom-${activeMedicationFormEntry.basketId}`}
                              labelText="Custom frequency"
                              maxLength={100}
                              value={activeMedicationFormEntry.frequency}
                              onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'frequency', event.target.value)}
                              disabled={basketSigning || hasUncertainBasketEntry}
                              required
                            />
                          ) : null}
                        </div>
                        <div className="clinician-medication-basket__duration">
                          {activeMedicationFormEntry.durationUnit === CUSTOM_MEDICATION_DURATION ? (
                            <TextInput
                              id={`basket-duration-custom-${activeMedicationFormEntry.basketId}`}
                              labelText="Custom duration"
                              maxLength={100}
                              value={activeMedicationFormEntry.customDuration}
                              onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'customDuration', event.target.value)}
                              disabled={basketSigning || hasUncertainBasketEntry}
                              required
                            />
                          ) : (
                            <TextInput
                              id={`basket-duration-${activeMedicationFormEntry.basketId}`}
                              labelText="Duration"
                              type="number"
                              min="1"
                              step="1"
                              value={activeMedicationFormEntry.durationValue}
                              onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'durationValue', event.target.value)}
                              disabled={basketSigning || hasUncertainBasketEntry}
                              required
                            />
                          )}
                          <Select
                            id={`basket-duration-unit-${activeMedicationFormEntry.basketId}`}
                            labelText="Duration unit"
                            value={activeMedicationFormEntry.durationUnit}
                            onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'durationUnit', event.target.value)}
                            disabled={basketSigning || hasUncertainBasketEntry}
                            required
                          >
                            <SelectItem value="" text="Select a unit" />
                            {MEDICATION_DURATION_UNITS.map(({ value, label }) => (
                              <SelectItem key={value} value={value} text={label} />
                            ))}
                            <SelectItem value={CUSTOM_MEDICATION_DURATION} text={OTHER_MEDICATION_OPTION} />
                          </Select>
                        </div>
                      </div>
                    </section>
                    <section className="clinician-medication-basket__section" aria-labelledby="clinician-medication-additional-details">
                      <h3 id="clinician-medication-additional-details">Additional details</h3>
                      <div className="clinician-medication-basket__fields">
                        <TextInput
                          id={`basket-quantity-${activeMedicationFormEntry.basketId}`}
                          labelText="Quantity to dispense"
                          helperText={`In ${activeMedicationFormEntry.unitOfMeasure.toLocaleLowerCase()} (catalog stock unit).`}
                          type="number"
                          min="0.0001"
                          step="0.0001"
                          value={activeMedicationFormEntry.quantityValue}
                          onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'quantityValue', event.target.value)}
                          disabled={basketSigning || hasUncertainBasketEntry}
                        />
                        <TextInput
                          id={`basket-indication-${activeMedicationFormEntry.basketId}`}
                          labelText="Indication"
                          maxLength={1000}
                          value={activeMedicationFormEntry.indication}
                          onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'indication', event.target.value)}
                          disabled={basketSigning || hasUncertainBasketEntry}
                        />
                        <div className="clinician-medication-basket__field--full">
                          <TextArea
                            id={`basket-instructions-${activeMedicationFormEntry.basketId}`}
                            labelText="Patient instructions"
                            helperText="Additional directions, e.g. take with food."
                            maxLength={2000}
                            rows={3}
                            value={activeMedicationFormEntry.dosingInstruction}
                            onChange={(event) => updateBasketEntry(activeMedicationFormEntry.basketId, 'dosingInstruction', event.target.value)}
                            disabled={basketSigning || hasUncertainBasketEntry}
                          />
                        </div>
                      </div>
                    </section>
                  </div>
                </>
              ) : (
                <>
                  <Search
                    id="clinician-medication-search"
                    size="md"
                    labelText="Search the medication catalog"
                    placeholder="Search for a medicine"
                    value={medicationSearch}
                    onChange={(event) => setMedicationSearch(event.currentTarget.value)}
                    disabled={!props.canRecordPrescribing || basketSigning || hasUncertainBasketEntry}
                  />
                  {medicationSearch.trim().length >= 2 ? (
                    <div className="clinician-medication-search-results">
                      {matchingMedicationCatalogEntries.slice(0, 8).map((item) => {
                        const stagedEntry = basketEntries.find((entry) => entry.id === item.id);

                        return (
                          <div className="clinician-medication-search-result" key={item.id}>
                            <div className="clinician-medication-search-result__item">
                              <strong>{item.name}</strong>
                              <span>Unit: {item.unitOfMeasure}</span>
                            </div>
                            <div className="clinician-medication-search-result__actions">
                              <Button
                                type="button"
                                kind={stagedEntry ? 'danger--ghost' : 'tertiary'}
                                size="sm"
                                renderIcon={stagedEntry ? TrashCan : Add}
                                disabled={basketSigning || hasUncertainBasketEntry}
                                onClick={() => stagedEntry
                                  ? removeMedicationFromBasket(stagedEntry.basketId)
                                  : addMedicationToBasket(item)}
                              >
                                {stagedEntry ? 'Remove' : 'Add to basket'}
                              </Button>
                              <Button
                                type="button"
                                kind="ghost"
                                size="sm"
                                renderIcon={Edit}
                                disabled={basketSigning || hasUncertainBasketEntry}
                                onClick={() => openMedicationOrderForm(item)}
                              >
                                Order form
                              </Button>
                            </div>
                          </div>
                        );
                      })}
                      {matchingMedicationCatalogEntries.length === 0 ? (
                        <p role="status">No medicines match this search.</p>
                      ) : null}
                    </div>
                  ) : null}
                </>
              )
            ) : (
              <>
                <Search
                  id="clinician-lab-search"
                  size="md"
                  labelText="Search lab tests and panels"
                  placeholder="Search by test or panel"
                  value={labSearch}
                  onChange={(event) => setLabSearch(event.currentTarget.value)}
                  disabled={labSubmitting || labOrderAttempt !== null}
                />
                {labCatalogEntries.length === 0 ? (
                  <p className="clinician-panel__description">
                    No orderable lab tests or panels are available for this facility.
                  </p>
                ) : null}
                {labSearch.trim().length >= 2 ? (
                  <div className="clinician-medication-search-results">
                    {matchingLabCatalogEntries.slice(0, 8).map((entry) => {
                      const staged = labBasketEntries.some(
                        (item) => item.type === entry.type && item.id === entry.id,
                      );

                      return (
                        <div className="clinician-medication-search-result" key={`${entry.type}-${entry.id}`}>
                          <div>
                            <strong>{entry.name}</strong>
                            <span>
                              {entry.code}
                              {entry.category ? ` · ${entry.category}` : ''}
                              {entry.specimenType ? ` · ${entry.specimenType}` : ''}
                              {entry.type === 'panel' ? ' · Panel' : ''}
                            </span>
                          </div>
                          <Button
                            type="button"
                            kind={staged ? 'danger--ghost' : 'tertiary'}
                            size="sm"
                            renderIcon={staged ? TrashCan : Add}
                            disabled={labSubmitting || labOrderAttempt !== null}
                            onClick={() => staged ? removeLabEntry(entry) : addLabEntry(entry)}
                          >
                            {staged ? 'Remove' : 'Add to basket'}
                          </Button>
                        </div>
                      );
                    })}
                    {matchingLabCatalogEntries.length === 0 ? (
                      <p role="status">No lab tests or panels match this search.</p>
                    ) : null}
                  </div>
                ) : null}
              </>
            )}
          </DockedPanel>
        ) : null}
        {noteToAmend ? (
          <DockedPanel
            heading="Amend signed clinical note"
            ariaLabel="Amend signed clinical note"
            description={noteToAmend.title ?? noteToAmend.noteType.replaceAll('_', ' ')}
            placement="right"
            width="min(38rem, calc(100vw - 4rem))"
            offsetInlineEnd="3rem"
            onClose={closeAmendmentPanel}
            onSubmit={submitNoteAmendment}
            primaryLabel={amendmentSubmitting ? 'Saving amendment…' : 'Append amendment'}
            primaryDisabled={amendmentSubmitting || !amendmentForm.body.trim() || !amendmentForm.reason.trim()}
            noValidate
          >
            <p>This appends a correction to the signed record. The original note remains unchanged.</p>
            <section className="clinician-recorded__preview" aria-label="Signed note">
              <strong>Signed note</strong>
              <p>{noteToAmend.body}</p>
            </section>
            {noteActionError ? (
              <InlineNotification kind="error" title={noteActionError} lowContrast />
            ) : null}
            <Select
              id="clinical-note-amendment-type"
              labelText="Amendment type"
              value={amendmentForm.amendment_type}
              onChange={(event) => setAmendmentForm((current) => ({
                ...current,
                amendment_type: event.target.value,
              }))}
              invalid={Boolean(amendmentErrors.amendment_type)}
              invalidText={amendmentErrors.amendment_type}
              disabled={amendmentSubmitting}
            >
              <SelectItem value="ADDENDUM" text="Addendum" />
              <SelectItem value="CORRECTION" text="Correction" />
              <SelectItem value="AMENDMENT" text="Amendment" />
            </Select>
            <TextArea
              id="clinical-note-amendment-body"
              labelText="Addendum or correction"
              value={amendmentForm.body}
              onChange={(event) => setAmendmentForm((current) => ({
                ...current,
                body: event.target.value,
              }))}
              invalid={Boolean(amendmentErrors.body)}
              invalidText={amendmentErrors.body}
              disabled={amendmentSubmitting}
              rows={8}
              required
            />
            <TextInput
              id="clinical-note-amendment-reason"
              labelText="Reason for amendment"
              value={amendmentForm.reason}
              onChange={(event) => setAmendmentForm((current) => ({
                ...current,
                reason: event.target.value,
              }))}
              invalid={Boolean(amendmentErrors.reason)}
              invalidText={amendmentErrors.reason}
              disabled={amendmentSubmitting}
              required
            />
          </DockedPanel>
        ) : null}
      </div>
      <ConfirmDialog
        open={noteToSign !== null}
        heading="Sign this clinical note?"
        body={noteToSign
          ? `Signing attests to "${noteToSign.title ?? noteToSign.noteType.replaceAll('_', ' ')}". The signed note cannot be edited; later corrections must be appended as amendments.`
          : ''}
        confirmLabel={signingNote ? 'Signing…' : 'Confirm signature'}
        primaryButtonDisabled={signingNote}
        onClose={() => {
          if (!signingNote) {
            setSigningNoteId(null);
          }
        }}
        onConfirm={signSavedNote}
      />
      <ConfirmDialog
        open={amendmentDiscardDialogOpen}
        heading="Discard this amendment?"
        body="This amendment has not been saved. Discarding it clears the text; the signed note remains unchanged."
        confirmLabel="Discard amendment"
        danger
        onClose={() => setAmendmentDiscardDialogOpen(false)}
        onConfirm={discardAmendmentDraft}
      />
      <ConfirmDialog
        open={discardTarget !== null}
        heading="Discard this documentation draft?"
        body={`This removes the unsaved ${
          discardTarget === 'note' ? 'note' : discardTarget === 'diagnosis' ? 'diagnosis' : 'plan'
        } draft from this encounter and clears its fields. Recorded clinical information is not affected.`}
        confirmLabel={discardingDraft ? 'Discarding…' : 'Discard draft'}
        danger
        onClose={() => {
          if (!discardingDraft) {
            setDiscardTarget(null);
          }
        }}
        onConfirm={() => {
          void discardDocumentationDraft();
        }}
      />
      <ConfirmDialog
        open={completeDialogOpen}
        heading="Complete this visit?"
        body={`Disposition: ${dispositionForm.data.disposition.replaceAll('_', ' ')}. `
          + `${props.notes.length} note(s), ${props.diagnoses.length} diagnosis(es), and ${props.plans.length} plan(s) are recorded for this encounter. `
          + 'Completion closes the visit.'}
        confirmLabel="Complete visit"
        onClose={() => setCompleteDialogOpen(false)}
        onConfirm={() => {
          setCompleteDialogOpen(false);
          completeEncounter();
        }}
      />
      </div>
      </div>
    </AppLayout>
  );
}
