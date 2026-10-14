import { Component, HostListener, OnDestroy, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from './auth.service';
import { LibraryItemType, LibraryStatistics, MusicApiService, MusicRadio, QueueItem, RandomPreference, SearchEntity, SearchTrack } from './music-api.service';

type SearchType = 'all' | 'track' | 'artist' | 'album' | 'playlist' | 'genre';
type MainView = 'home' | 'search' | 'artists' | 'albums' | 'radios' | 'stats' | 'queue';

@Component({
  selector: 'app-root',
  imports: [FormsModule],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App implements OnDestroy {
  readonly auth = inject(AuthService);
  private readonly api = inject(MusicApiService);

  readonly view = signal<MainView>('home');
  readonly queue = signal<QueueItem[]>([]);
  readonly artists = signal<SearchEntity[]>([]);
  readonly albums = signal<SearchEntity[]>([]);
  readonly preferences = signal<Record<string, RandomPreference>>({});
  readonly radios = signal<MusicRadio[]>([]);
  readonly statistics = signal<LibraryStatistics | null>(null);
  readonly statisticsLoading = signal(false);
  readonly radioSearch = signal('');
  readonly radioName = signal('');
  readonly selectedRadioArtists = signal<string[]>([]);
  readonly editingRadioId = signal<number | null>(null);
  readonly savingRadio = signal(false);
  readonly selectedArtist = signal<SearchEntity | null>(null);
  readonly libraryQuery = signal('');
  readonly artistsLoading = signal(false);
  readonly albumsLoading = signal(false);
  readonly radiosLoading = signal(false);
  readonly skeletonCards = [1, 2, 3, 4, 5, 6];
  readonly pendingCount = signal(0);
  readonly loadingQueue = signal(false);
  readonly syncingLibrary = signal(false);
  readonly booting = signal(true);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly linked = signal(false);
  readonly pairCode = signal('');
  readonly pairLoading = signal(false);
  readonly query = signal('');
  readonly searchType = signal<SearchType>('all');
  readonly searchTracks = signal<SearchTrack[]>([]);
  readonly searchEntities = signal<SearchEntity[]>([]);
  readonly searchLoading = signal(false);
  readonly searchMore = signal(false);
  readonly searchTotal = signal(0);
  readonly searchOffset = signal(0);
  readonly searching = computed(() => this.view() === 'search');
  readonly editableQueue = computed(() => this.queue().filter(item => !item.locked));
  readonly nowPlaying = computed(() => this.queue().reduce<QueueItem | null>((latest, item) => {
    return item.locked && (!latest || item.index > latest.index) ? item : latest;
  }, null));

  private dragIndex: number | null = null;
  private searchTimer: ReturnType<typeof setTimeout> | undefined;
  private librarySearchTimer: ReturnType<typeof setTimeout> | undefined;
  private searchRequestId = 0;
  private artistsRequestId = 0;
  private albumsRequestId = 0;
  private authUnsubscribe?: () => void;

  constructor() {
    this.authUnsubscribe = this.auth.listen(user => {
      this.booting.set(false);
      if (user) void this.loadDashboard();
      else {
        this.queue.set([]);
        this.artists.set([]);
        this.albums.set([]);
        this.preferences.set({});
        this.radios.set([]);
        this.statistics.set(null);
        this.selectedArtist.set(null);
        this.linked.set(false);
        this.booting.set(false);
      }
    });
  }

  ngOnDestroy(): void {
    this.authUnsubscribe?.();
    if (this.searchTimer) clearTimeout(this.searchTimer);
    if (this.librarySearchTimer) clearTimeout(this.librarySearchTimer);
  }

  @HostListener('document:keydown', ['$event'])
  onGlobalKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && this.auth.user()) {
      event.preventDefault();
      this.openSearch();
      window.setTimeout(() => document.querySelector<HTMLInputElement>('.search-box input')?.focus(), 0);
    }
    if (event.key === 'Escape' && this.searching()) this.openQueue();
  }

  async signInWithGoogle(): Promise<void> {
    this.error.set('');
    this.busy.set(true);
    try {
      await this.auth.signInWithGoogle();
    } catch (error) {
      this.error.set(this.auth.message(error));
    } finally {
      this.busy.set(false);
    }
  }

  async signOut(): Promise<void> {
    await this.auth.signOut();
  }

  async loadDashboard(): Promise<void> {
    this.error.set('');
    this.loadingQueue.set(true);
    try {
      const pair = await this.api.getPairStatus();
      this.linked.set(pair.linked);
      if (!pair.linked) {
        this.queue.set([]);
        this.pendingCount.set(0);
        this.view.set('queue');
        return;
      }
      const [queue, preferences, radios] = await Promise.all([
        this.api.getQueue(), this.api.getRandomPreferences(), this.api.getRadios(),
      ]);
      this.queue.set(queue.items ?? []);
      this.preferences.set(Object.fromEntries(preferences.map(item => [`${item.type}:${item.id}`, item])));
      this.radios.set(radios);
      this.pendingCount.set(queue.pending_count ?? queue.items?.filter(item => !item.locked).length ?? 0);
      void this.loadArtists('');
      void this.loadAlbums('', '');
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.loadingQueue.set(false);
    }
  }

  async syncLibrary(): Promise<void> {
    if (this.syncingLibrary()) return;
    this.syncingLibrary.set(true);
    this.error.set('');
    try {
      const result = await this.api.syncLibrary();
      if (this.view() === 'artists') await this.loadArtists(this.libraryQuery());
      else if (this.view() === 'albums') await this.loadAlbums(this.selectedArtist()?.id ?? '', this.libraryQuery());
      else if (this.view() === 'home') {
        await Promise.all([this.loadArtists(''), this.loadAlbums('', '')]);
      } else if (this.view() === 'search' && this.query().trim().length >= 2) {
        this.searchRequestId++;
        this.searchLoading.set(false);
        this.searchOffset.set(0);
        await this.search();
      }
      const { tracks, artists, albums, playlists } = result.counts;
      this.notice.set(`Biblioteca sincronizada: ${tracks} canciones, ${albums} álbumes, ${artists} artistas y ${playlists} playlists`);
      window.setTimeout(() => this.notice.set(''), 6000);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.syncingLibrary.set(false);
    }
  }

  openQueue(): void { this.view.set('queue'); this.error.set(''); }
  openSearch(): void { this.view.set('search'); this.error.set(''); }

  async openStatistics(): Promise<void> {
    this.view.set('stats');
    this.error.set('');
    await this.loadStatistics();
  }

  private async loadStatistics(): Promise<void> {
    this.statisticsLoading.set(true);
    try { this.statistics.set(await this.api.getLibraryStatistics()); }
    catch (error) { this.error.set(this.apiMessage(error)); }
    finally { this.statisticsLoading.set(false); }
  }

  openHome(): void { this.view.set('home'); this.selectedArtist.set(null); this.error.set(''); }

  async openArtists(): Promise<void> {
    this.libraryQuery.set('');
    this.view.set('artists');
    this.selectedArtist.set(null);
    await this.loadArtists('');
  }

  async openAlbums(artist: SearchEntity | null = null): Promise<void> {
    this.libraryQuery.set('');
    this.selectedArtist.set(artist);
    this.view.set('albums');
    await this.loadAlbums(artist?.id ?? '', '');
  }

  async filterLibrary(value: string): Promise<void> {
    this.libraryQuery.set(value);
    if (this.librarySearchTimer) clearTimeout(this.librarySearchTimer);
    this.librarySearchTimer = setTimeout(() => {
      if (this.view() === 'artists') void this.loadArtists(value);
      else if (this.view() === 'albums') void this.loadAlbums(this.selectedArtist()?.id ?? '', value);
    }, 220);
  }

  async playArtist(artist: SearchEntity): Promise<void> {
    if (!artist.id) return;
    await this.replaceCollection(() => this.api.replaceArtist(artist.id!), `La cola se ha sustituido por ${artist.name}`);
  }

  async playRandomGroup(): Promise<void> {
    await this.addCollection(async () => {
      const result = await this.api.addRandomGroup();
      return `Grupo aleatorio: ${result.artist} preparado para Alexa`;
    }, 'Grupo aleatorio preparado para Alexa');
  }

  async toggleRandomPreference(type: LibraryItemType, id: string | undefined, field: 'exclude_group_random' | 'exclude_random'): Promise<void> {
    if (!id) return;
    const key = `${type}:${id}`;
    const current = this.preferences()[key] ?? { type, id, exclude_group_random: false, exclude_random: false };
    const updated = { ...current, [field]: !current[field] };
    this.preferences.update(items => ({ ...items, [key]: updated }));
    try {
      const saved = await this.api.saveRandomPreference(updated);
      this.preferences.update(items => ({ ...items, [key]: saved }));
    } catch (error) {
      this.preferences.update(items => ({ ...items, [key]: current }));
      this.error.set(this.apiMessage(error));
    }
  }

  isExcluded(type: LibraryItemType, id: string | undefined, field: 'exclude_group_random' | 'exclude_random'): boolean {
    return id ? Boolean(this.preferences()[`${type}:${id}`]?.[field]) : false;
  }

  async openRadios(): Promise<void> {
    this.view.set('radios');
    this.radioSearch.set('');
    this.startNewRadio();
    await Promise.all([this.loadArtists(''), this.loadRadios()]);
  }

  private async loadRadios(): Promise<void> {
    this.radiosLoading.set(true);
    try { this.radios.set(await this.api.getRadios()); }
    catch (error) { this.error.set(this.apiMessage(error)); }
    finally { this.radiosLoading.set(false); }
  }

  async filterRadioArtists(value: string): Promise<void> {
    this.radioSearch.set(value);
    await this.loadArtists(value);
  }

  startNewRadio(): void {
    this.editingRadioId.set(null);
    this.radioName.set('');
    this.selectedRadioArtists.set([]);
  }

  editRadio(radio: MusicRadio): void {
    this.editingRadioId.set(radio.id);
    this.radioName.set(radio.name);
    this.selectedRadioArtists.set([...radio.artist_ids]);
  }

  toggleRadioArtist(id: string | undefined): void {
    if (!id) return;
    this.selectedRadioArtists.update(ids => ids.includes(id) ? ids.filter(value => value !== id) : [...ids, id]);
  }

  async saveRadio(): Promise<void> {
    const name = this.radioName().trim();
    const artist_ids = this.selectedRadioArtists();
    if (!name || !artist_ids.length) {
      this.error.set('Escribe un nombre y selecciona al menos un artista.');
      return;
    }
    this.savingRadio.set(true);
    this.error.set('');
    try {
      await this.api.saveRadio({ name, artist_ids, ...(this.editingRadioId() ? { radio_id: this.editingRadioId()! } : {}) });
      await this.loadRadios();
      this.notice.set(`Radio «${name}» guardada.`);
      window.setTimeout(() => this.notice.set(''), 5000);
      this.startNewRadio();
    } catch (error) { this.error.set(this.apiMessage(error)); }
    finally { this.savingRadio.set(false); }
  }

  async deleteRadio(radio: MusicRadio): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.deleteRadio(radio.id);
      this.radios.update(items => items.filter(item => item.id !== radio.id));
      if (this.editingRadioId() === radio.id) this.startNewRadio();
    } catch (error) { this.error.set(this.apiMessage(error)); }
    finally { this.busy.set(false); }
  }

  async playRadio(radio: MusicRadio): Promise<void> {
    await this.replaceCollection(() => this.api.replaceRadio(radio.id), `La cola se ha sustituido por la radio «${radio.name}»`);
  }

  async playAlbum(album: SearchEntity): Promise<void> {
    if (!album.id) return;
    await this.replaceCollection(() => this.api.replaceAlbum(album.id!), `La cola se ha sustituido por «${album.name}»`);
  }

  async playEntity(entity: SearchEntity): Promise<void> {
    if (this.searchType() === 'artist') return this.playArtist(entity);
    if (this.searchType() === 'album') return this.playAlbum(entity);
    this.showEntityTracks(entity);
  }

  async playTrack(track: SearchTrack): Promise<void> {
    await this.addTrack(track);
    if (!this.error()) this.notice.set(`«${track.title}» añadida a la cola de Alexa`);
  }

  async playAllSearchResults(): Promise<void> { await this.addTracks(this.searchTracks().map(track => track.id)); }

  private async loadArtists(q = this.libraryQuery()): Promise<void> {
    const requestId = ++this.artistsRequestId;
    this.artistsLoading.set(true);
    try {
      const response = await this.api.getArtists(q, 60);
      if (requestId === this.artistsRequestId) this.artists.set(response.items ?? []);
    } catch (error) { if (requestId === this.artistsRequestId) this.error.set(this.apiMessage(error)); }
    finally { if (requestId === this.artistsRequestId) this.artistsLoading.set(false); }
  }

  private async loadAlbums(artistId = this.selectedArtist()?.id ?? '', q = this.libraryQuery()): Promise<void> {
    const requestId = ++this.albumsRequestId;
    this.albumsLoading.set(true);
    try {
      const response = await this.api.getAlbums(q, artistId, 60);
      if (requestId === this.albumsRequestId) this.albums.set(response.items ?? []);
    } catch (error) { if (requestId === this.albumsRequestId) this.error.set(this.apiMessage(error)); }
    finally { if (requestId === this.albumsRequestId) this.albumsLoading.set(false); }
  }

  private async replaceCollection(action: () => Promise<void>, message: string): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      await action();
      await this.loadQueueOnly();
      this.notice.set(`${message}. Alexa puede terminar el audio que ya tenía almacenado.`);
      window.setTimeout(() => this.notice.set(''), 6000);
    } catch (error) { this.error.set(this.apiMessage(error)); }
    finally { this.busy.set(false); }
  }

  private async addCollection(action: () => Promise<string | void>, message: string): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      const resultMessage = await action();
      await this.loadQueueOnly();
      this.notice.set(`${resultMessage ?? message}. Abre la skill de música en tu Echo para empezar.`);
      window.setTimeout(() => this.notice.set(''), 5000);
    } catch (error) { this.error.set(this.apiMessage(error)); }
    finally { this.busy.set(false); }
  }

  entityArt(entity: SearchEntity): string { return this.albumArt({ id: entity.id, title: entity.name, album: entity.artist ?? '' }); }

  setSearchType(type: SearchType): void {
    this.searchRequestId++;
    this.searchLoading.set(false);
    this.searchType.set(type);
    this.searchOffset.set(0);
    this.searchTracks.set([]);
    this.searchEntities.set([]);
    if (this.query().trim()) void this.search();
  }

  onQueryChange(value: string): void {
    this.searchRequestId++;
    this.searchLoading.set(false);
    this.query.set(value);
    this.searchOffset.set(0);
    this.searchTracks.set([]);
    this.searchEntities.set([]);
    if (this.searchTimer) clearTimeout(this.searchTimer);
    if (value.trim().length < 2) {
      this.searchTotal.set(0);
      this.searchLoading.set(false);
      return;
    }
    this.searchTimer = setTimeout(() => void this.search(), 220);
  }

  async search(loadMore = false): Promise<void> {
    const q = this.query().trim();
    if (q.length < 2 || this.searchLoading()) return;
    const requestId = ++this.searchRequestId;
    const offset = loadMore ? this.searchOffset() : 0;
    this.searchLoading.set(true);
    this.error.set('');
    try {
      const response = await this.api.search(q, this.searchType(), 30, offset);
      if (requestId !== this.searchRequestId) return;
      const tracks = response.tracks ?? [];
      const entities = this.searchType() === 'artist' ? response.artists
        : this.searchType() === 'album' ? response.albums
          : this.searchType() === 'playlist' ? response.playlists
            : this.searchType() === 'genre' ? response.genres : [];
      this.searchTracks.set(loadMore ? [...this.searchTracks(), ...tracks] : tracks);
      this.searchEntities.set(loadMore ? [...this.searchEntities(), ...entities] : entities);
      this.searchOffset.set(offset + (entities.length || tracks.length));
      const totalKey = (this.searchType() === 'all' || this.searchType() === 'track' ? 'tracks' : this.searchType()) as keyof NonNullable<typeof response.totals>;
      this.searchTotal.set(response.totals?.[totalKey] ?? tracks.length);
    } catch (error) {
      if (requestId === this.searchRequestId) this.error.set(this.apiMessage(error));
    } finally {
      if (requestId === this.searchRequestId) this.searchLoading.set(false);
    }
  }

  async addTrack(track: SearchTrack): Promise<void> { await this.addTracks([track.id]); }

  showEntityTracks(entity: SearchEntity): void {
    this.searchType.set('all');
    this.onQueryChange(entity.name);
  }

  hasSearchResults(): boolean { return this.searchTracks().length > 0 || this.searchEntities().length > 0; }
  resultLength(): number { return this.searchType() === 'all' || this.searchType() === 'track' ? this.searchTracks().length : this.searchEntities().length; }
  resultCountLabel(): string {
    return this.searchType() === 'artist' ? 'artistas' : this.searchType() === 'album' ? 'álbumes'
      : this.searchType() === 'playlist' ? 'playlists' : this.searchType() === 'genre' ? 'géneros' : 'canciones';
  }

  async addTracks(ids: string[]): Promise<void> {
    if (!ids.length) return;
    this.busy.set(true);
    this.error.set('');
    try {
      await this.api.addTracks(ids);
      await this.loadQueueOnly();
      this.notice.set(ids.length === 1 ? 'Añadida a la cola' : `${ids.length} canciones añadidas`);
      window.setTimeout(() => this.notice.set(''), 2600);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.busy.set(false);
    }
  }

  async remove(item: QueueItem): Promise<void> {
    if (item.locked || this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      await this.api.removeAt(item.index);
      await this.loadQueueOnly();
      this.notice.set('Canción quitada de la cola');
      window.setTimeout(() => this.notice.set(''), 2600);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.busy.set(false);
    }
  }

  async move(item: QueueItem, delta: number): Promise<void> {
    const current = this.queue().findIndex(candidate => candidate.index === item.index);
    const destination = current + delta;
    const target = this.queue()[destination];
    if (!target || item.locked || target.locked || this.busy()) return;
    await this.reorder(item.index, target.index);
  }

  startDrag(event: DragEvent, item: QueueItem): void {
    if (item.locked) { event.preventDefault(); return; }
    this.dragIndex = item.index;
    event.dataTransfer?.setData('text/plain', String(item.index));
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
  }

  allowDrop(event: DragEvent): void { event.preventDefault(); if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'; }

  async dropOn(event: DragEvent, target: QueueItem): Promise<void> {
    event.preventDefault();
    const from = this.dragIndex ?? Number(event.dataTransfer?.getData('text/plain'));
    this.dragIndex = null;
    if (!Number.isFinite(from) || target.locked || from === target.index) return;
    await this.reorder(from, target.index);
  }

  async clearPending(): Promise<void> {
    if (this.pendingCount() === 0 || this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      await this.api.clearQueue();
      await this.loadQueueOnly();
      this.notice.set('Cola pendiente vaciada');
      window.setTimeout(() => this.notice.set(''), 2600);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.busy.set(false);
    }
  }

  async startPairing(): Promise<void> {
    this.pairLoading.set(true);
    this.error.set('');
    try {
      const result = await this.api.startPairing();
      this.pairCode.set(result.code);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.pairLoading.set(false);
    }
  }

  async checkPairing(): Promise<void> {
    this.pairLoading.set(true);
    this.error.set('');
    try {
      const status = await this.api.getPairStatus();
      this.linked.set(status.linked);
      if (status.linked) {
        this.pairCode.set('');
        await this.loadDashboard();
        this.notice.set('Alexa ya está vinculada');
      }
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.pairLoading.set(false);
    }
  }

  async unpair(): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      await this.api.unpair();
      this.linked.set(false);
      this.pairCode.set('');
      this.queue.set([]);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.busy.set(false);
    }
  }

  trackByIndex(_: number, item: QueueItem): number { return item.index; }
  trackById(_: number, track: SearchTrack): string { return track.id; }
  duration(value?: number | string): string {
    const seconds = Number(value ?? 0);
    if (!seconds) return '—';
    return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
  }
  formatListeningTime(value: number): string {
    const totalMinutes = Math.floor(Math.max(0, value) / 60);
    const hours = Math.floor(totalMinutes / 60);
    if (hours >= 24) return `${Math.floor(hours / 24).toLocaleString('es-ES')} d ${hours % 24} h`;
    if (hours > 0) return `${hours} h ${totalMinutes % 60} min`;
    return `${totalMinutes} min`;
  }
  formatStatsMonth(value: string): string {
    const [year, month] = value.split('-').map(Number);
    const label = new Intl.DateTimeFormat('es-ES', { month: 'short' }).format(new Date(year, month - 1, 1));
    return `${label} ${String(year).slice(-2)}`;
  }
  formatStatsDate(value: string | null): string {
    if (!value) return 'fecha desconocida';
    const date = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' }).format(date);
  }
  statsShare(value: number, maximum: number): number {
    return maximum > 0 ? Math.max(4, Math.round(value / maximum * 100)) : 0;
  }
  maxTimelineCount(stats: LibraryStatistics): number {
    return stats.timeline.reduce((max, month) => Math.max(max, month.finished, month.ignored), 0);
  }
  albumArt(track: { id?: string; track_id?: string; title: string; album?: string }): string {
    const colors = ['mint', 'peach', 'lilac', 'blue', 'yellow'];
    const source = `${track.id ?? track.track_id ?? ''}${track.title}${track.album ?? ''}`;
    let hash = 0;
    for (const char of source) hash = (hash * 31 + char.charCodeAt(0)) | 0;
    return colors[Math.abs(hash) % colors.length];
  }

  private async reorder(from: number, to: number): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      await this.api.reorder(from, to);
      await this.loadQueueOnly();
      this.notice.set('Orden actualizado');
      window.setTimeout(() => this.notice.set(''), 2000);
    } catch (error) {
      this.error.set(this.apiMessage(error));
    } finally {
      this.busy.set(false);
    }
  }

  private async loadQueueOnly(): Promise<void> {
    const queue = await this.api.getQueue();
    this.queue.set(queue.items ?? []);
    this.pendingCount.set(queue.pending_count ?? queue.items?.filter(item => !item.locked).length ?? 0);
  }

  private apiMessage(error: unknown): string {
    const message = error instanceof Error ? error.message : '';
    if (message.includes('NO_ARTIST_WITH_ENOUGH_TRACKS')) return 'No hay ningún artista con más de 10 canciones en tu biblioteca.';
    if (message.includes('ALEXA_NOT_PAIRED')) return 'Vincula esta cuenta con Alexa para gestionar la cola.';
    if (message.includes('QUEUE_POSITION_LOCKED')) return 'Alexa ya ha recibido esa canción. La cola se ha actualizado.';
    if (message.includes('AUTH_REQUIRED')) return 'La sesión ha caducado. Vuelve a iniciar sesión.';
    return message || 'No se ha podido conectar con la API. Comprueba la conexión e inténtalo de nuevo.';
  }

}
