import { Button, IconButton, InlineLoading, InlineNotification, Modal, SkeletonText, TextArea } from '@carbon/react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Document, Edit, TrashCan } from '@/icons';
import { sendJson } from '@/lib/http';
import { formatClinicalDateTime } from '@/lib/formatting';
import { permissions, useCan } from '@/lib/authorization';

const MAX_NOTE_LENGTH = 300;

interface StickyNote {
  id: string;
  body: string;
  author: string | null;
  updatedBy: string | null;
  createdAt: string | null;
  updatedAt: string | null;
}

interface StickyNoteResponse {
  stickyNote: StickyNote | null;
}

function endpoint(patientId: string, noteId?: string): string {
  const base = `/patient/${encodeURIComponent(patientId)}/sticky-note`;
  return noteId ? `${base}/${encodeURIComponent(noteId)}` : base;
}

export function PatientStickyNote({ patientId }: { patientId: string }): ReactNode {
  const containerRef = useRef<HTMLElement>(null);
  const can = useCan();
  const canEdit = can(permissions.patient.update);
  const [note, setNote] = useState<StickyNote | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [panelOpen, setPanelOpen] = useState(false);
  const [editorOpen, setEditorOpen] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [saving, setSaving] = useState(false);
  const [archiving, setArchiving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const loadNote = useCallback(async (): Promise<void> => {
    setLoading(true);
    setLoadError(null);
    try {
      const result = await sendJson<StickyNoteResponse>(endpoint(patientId));
      setNote(result.stickyNote);
    } catch (error) {
      setLoadError(error instanceof Error ? error.message : 'Unable to load the sticky note.');
    } finally {
      setLoading(false);
    }
  }, [patientId]);

  useEffect(() => {
    void loadNote();
  }, [loadNote]);

  useEffect(() => {
    if (!panelOpen) return;

    const closePanel = (event: MouseEvent) => {
      const target = event.target;
      if (target instanceof HTMLElement && target.closest('[role="dialog"]')) return;
      if (target instanceof Node && !containerRef.current?.contains(target)) setPanelOpen(false);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setPanelOpen(false);
    };

    document.addEventListener('mousedown', closePanel);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('mousedown', closePanel);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [panelOpen]);

  function startEditing(): void {
    setDraft(note?.body ?? '');
    setActionError(null);
    setEditorOpen(true);
  }

  async function saveNote(): Promise<void> {
    const body = draft.trim();
    if (!body || body.length > MAX_NOTE_LENGTH) return;

    setSaving(true);
    setActionError(null);
    try {
      const result = await sendJson<StickyNoteResponse>(
        endpoint(patientId, note?.id),
        note ? 'PATCH' : 'POST',
        { body },
      );
      setNote(result.stickyNote);
      setEditorOpen(false);
      setPanelOpen(true);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Unable to save the sticky note.');
    } finally {
      setSaving(false);
    }
  }

  async function archiveNote(): Promise<void> {
    if (!note) return;

    setArchiving(true);
    setActionError(null);
    try {
      await sendJson<{ archived: boolean }>(endpoint(patientId, note.id), 'DELETE');
      setNote(null);
      setPanelOpen(false);
      setArchiveOpen(false);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Unable to archive the sticky note.');
    } finally {
      setArchiving(false);
    }
  }

  return (
    <section className="patient-sticky-note" aria-label="Patient sticky note" ref={containerRef}>
      <div className="patient-sticky-note__trigger-row">
        <IconButton
          label="Sticky note"
          kind="ghost"
          size="sm"
          aria-expanded={panelOpen}
          aria-busy={loading}
          onClick={() => {
            if (loading || loadError || note) {
              setPanelOpen((open) => !open);
            } else if (canEdit) {
              startEditing();
            } else {
              setPanelOpen(true);
            }
          }}
        >
          <span className="patient-sticky-note__icon">
            <Document />
            {note ? <span className="patient-sticky-note__badge">1</span> : null}
          </span>
        </IconButton>
      </div>

      {panelOpen ? (
        <div className="patient-sticky-note__panel" role="region" aria-label="Sticky note details">
          {loading ? (
            <div className="patient-sticky-note__body" role="status" aria-label="Loading sticky note">
              <SkeletonText paragraph lineCount={3} />
            </div>
          ) : loadError ? (
            <div className="patient-sticky-note__error-state">
              <InlineNotification
                kind="error"
                lowContrast
                title="Sticky note unavailable"
                subtitle={loadError}
              />
              <Button kind="ghost" size="sm" onClick={() => void loadNote()}>Retry</Button>
            </div>
          ) : note ? (
            <>
              <p className="patient-sticky-note__body">{note.body}</p>
              {actionError ? <p className="patient-sticky-note__error" role="alert">{actionError}</p> : null}
              <footer className="patient-sticky-note__footer">
                <div className="patient-sticky-note__metadata">
                  {formatClinicalDateTime(note.updatedAt ?? note.createdAt)}
                  {(note.updatedBy ?? note.author) ? ` · ${note.updatedBy ?? note.author}` : ''}
                </div>
                {canEdit ? (
                  <div className="patient-sticky-note__actions">
                    <IconButton label="Edit sticky note" kind="ghost" size="sm" onClick={startEditing}>
                      <Edit />
                    </IconButton>
                    <IconButton label="Archive sticky note" kind="ghost" size="sm" onClick={() => setArchiveOpen(true)}>
                      <TrashCan />
                    </IconButton>
                  </div>
                ) : null}
              </footer>
            </>
          ) : null}
        </div>
      ) : null}

      {editorOpen ? (
        <Modal
          open
          modalHeading="Sticky note"
          primaryButtonText={saving ? 'Saving…' : 'Save'}
          secondaryButtonText="Cancel"
          primaryButtonDisabled={!draft.trim() || draft.length > MAX_NOTE_LENGTH || saving}
          onRequestClose={() => setEditorOpen(false)}
          onRequestSubmit={() => void saveNote()}
        >
          <TextArea
            id={`patient-sticky-note-${patientId}`}
            labelText="Sticky note"
            hideLabel
            placeholder="Enter your sticky note here..."
            value={draft}
            maxLength={MAX_NOTE_LENGTH}
            rows={4}
            enableCounter
            counterMode="character"
            onChange={(event) => setDraft(event.currentTarget.value)}
          />
          {actionError ? <p className="patient-sticky-note__error" role="alert">{actionError}</p> : null}
          {saving ? <InlineLoading description="Saving sticky note" /> : null}
        </Modal>
      ) : null}

      {archiveOpen ? (
        <Modal
          open
          modalHeading="Archive sticky note?"
          primaryButtonText={archiving ? 'Archiving…' : 'Archive'}
          secondaryButtonText="Cancel"
          danger
          primaryButtonDisabled={archiving}
          onRequestClose={() => setArchiveOpen(false)}
          onRequestSubmit={() => void archiveNote()}
        >
          <p>The note will be removed from the patient chart but retained in the audit history.</p>
          {actionError ? <p className="patient-sticky-note__error" role="alert">{actionError}</p> : null}
          {archiving ? <InlineLoading description="Archiving sticky note" /> : null}
        </Modal>
      ) : null}
    </section>
  );
}
