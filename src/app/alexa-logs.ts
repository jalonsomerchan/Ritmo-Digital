import { Component, OnDestroy, inject, signal } from '@angular/core';
import { JsonPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AlexaLog, AlexaLogDetail, AlexaLogStatus, MusicApiService } from './music-api.service';

@Component({
  selector: 'app-alexa-logs',
  imports: [FormsModule, JsonPipe],
  templateUrl: './alexa-logs.html',
  styleUrl: './alexa-logs.scss',
})
export class AlexaLogs implements OnDestroy {
  private readonly api = inject(MusicApiService);
  readonly items = signal<AlexaLog[]>([]);
  readonly status = signal<AlexaLogStatus | ''>('');
  readonly loading = signal(false);
  readonly loaded = signal(false);
  readonly error = signal('');
  readonly nextId = signal<string | null>(null);
  readonly selectedId = signal<string | null>(null);
  readonly detail = signal<AlexaLogDetail | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal('');
  readonly statuses: Array<{ value: AlexaLogStatus | ''; label: string }> = [
    { value: '', label: 'Todos los estados' },
    { value: 'responded', label: 'Con respuesta' },
    { value: 'error', label: 'Error' },
    { value: 'pending', label: 'Pendiente' },
    { value: 'no_response', label: 'Sin respuesta' },
  ];
  private requestId = 0;
  private detailRequestId = 0;

  constructor() { void this.load(); }

  ngOnDestroy(): void { this.requestId++; this.detailRequestId++; }

  async filter(value: AlexaLogStatus | ''): Promise<void> {
    this.status.set(value);
    await this.load();
  }

  async load(more = false): Promise<void> {
    if (more && (this.loading() || !this.nextId())) return;
    const requestId = ++this.requestId;
    const before = more ? this.nextId() : null;
    this.loading.set(true);
    this.error.set('');
    if (!more) {
      this.items.set([]);
      this.loaded.set(false);
      this.nextId.set(null);
      this.selectedId.set(null);
      this.detail.set(null);
      this.detailRequestId++;
    }
    try {
      const result = await this.api.getAlexaLogs(this.status(), before);
      if (requestId !== this.requestId) return;
      this.items.update(items => more ? [...items, ...result.items] : result.items);
      this.nextId.set(result.next_before_id);
      this.loaded.set(true);
    } catch (error) {
      if (requestId === this.requestId) this.error.set(this.message(error));
    } finally {
      if (requestId === this.requestId) this.loading.set(false);
    }
  }

  async toggleDetail(id: string): Promise<void> {
    const requestId = ++this.detailRequestId;
    if (this.selectedId() === id && !this.detailError()) {
      this.selectedId.set(null);
      return;
    }
    this.selectedId.set(id);
    this.detail.set(null);
    this.detailError.set('');
    this.detailLoading.set(true);
    try {
      const detail = await this.api.getAlexaLog(id);
      if (requestId === this.detailRequestId) this.detail.set(detail);
    } catch (error) {
      if (requestId === this.detailRequestId) this.detailError.set(this.message(error));
    } finally {
      if (requestId === this.detailRequestId) this.detailLoading.set(false);
    }
  }

  statusLabel(status: AlexaLogStatus): string {
    return this.statuses.find(item => item.value === status)?.label ?? status;
  }

  private message(error: unknown): string {
    const body = (error as { error?: { message?: string } })?.error;
    return body?.message ?? (error instanceof Error ? error.message : 'No se pudieron cargar los registros. Inténtalo de nuevo.');
  }
}
