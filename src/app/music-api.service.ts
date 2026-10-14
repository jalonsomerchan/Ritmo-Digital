import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './auth.service';
import { environment } from '../environments/environment';

export interface QueueItem {
  index: number;
  track_id: string;
  locked: boolean;
  title: string;
  artist?: string;
  album?: string;
  duration?: number | string;
  duration_seconds?: number | string;
  year?: number | string;
  genres?: string[] | string;
  artwork_url?: string | null;
  track?: SearchTrack | null;
}

export interface SearchTrack {
  id: string;
  artist_id?: string;
  album_id?: string;
  title: string;
  artist?: string;
  album?: string;
  duration?: number | string;
  duration_seconds?: number | string;
  year?: number | string;
  genres?: string[] | string;
  artwork_url?: string | null;
}

export interface QueueResponse {
  items: QueueItem[];
  count?: number;
  locked_through_index?: number;
  editable_from_index?: number;
  pending_count?: number;
}

export interface SearchResponse {
  tracks: SearchTrack[];
  artists: SearchEntity[];
  albums: SearchEntity[];
  playlists: SearchEntity[];
  genres: SearchEntity[];
  totals?: { tracks?: number; artists?: number; albums?: number; playlists?: number; genres?: number };
}

export interface LibrarySyncResponse {
  synced_at: string | null;
  counts: { tracks: number; artists: number; albums: number; playlists: number };
}

export type LibraryItemType = 'artist' | 'album' | 'track';

export interface RandomPreference {
  type: LibraryItemType;
  id: string;
  exclude_group_random: boolean;
  exclude_random: boolean;
}

export interface MusicRadio {
  id: number;
  name: string;
  artist_ids: string[];
}

export interface SearchEntity {
  id?: string;
  name: string;
  track_count?: number;
  year?: number | string;
  artist?: string;
  artist_id?: string;
  artwork_url?: string | null;
}

export interface LibraryStatistics {
  totals: {
    finished: number;
    ignored: number;
    listening_seconds: number;
    unique_tracks: number;
    unique_artists: number;
  };
  top_artists: Array<{ id: string; name: string; play_count: number; artwork_url?: string | null }>;
  top_albums: Array<{ id: string; name: string; artist: string; play_count: number; artwork_url?: string | null }>;
  top_tracks: Array<{ id: string; title: string; artist: string; album: string; play_count: number; artwork_url?: string | null }>;
  ignored_tracks: Array<{ id: string; title: string; artist: string; album: string; ignore_count: number; artwork_url?: string | null }>;
  timeline: Array<{ month: string; finished: number; ignored: number }>;
  recent: Array<{ track_id: string; title: string; artist: string; album: string; outcome: 'finished' | 'skipped'; event_at: string | null; heard_seconds: number; artwork_url?: string | null }>;
}

@Injectable({ providedIn: 'root' })
export class MusicApiService {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);
  private readonly baseUrl = environment.apiBaseUrl.replace(/\/$/, '');

  async getQueue(): Promise<QueueResponse> {
    const queue = this.unwrap<QueueResponse>(await firstValueFrom(this.http.get(this.url('queue'), { headers: await this.headers() })));
    return {
      ...queue,
      items: (queue.items ?? []).map(item => ({
        ...item,
        ...(item.track ?? {}),
        track_id: item.track_id,
        title: item.track?.title ?? item.title ?? 'Pista no disponible',
        duration: item.track?.duration_seconds ?? item.duration,
        locked: Boolean(item.locked),
      })),
    };
  }

  async getPairStatus(): Promise<{ linked: boolean }> {
    return this.unwrap<{ linked: boolean }>(await firstValueFrom(this.http.get(this.url('queue_pair_status'), { headers: await this.headers() })));
  }

  async startPairing(): Promise<{ code: string }> {
    return this.unwrap<{ code: string }>(await firstValueFrom(this.http.post(this.url('queue_pair_start'), {}, { headers: await this.headers() })));
  }

  async unpair(): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_unpair'), {}, { headers: await this.headers() }));
  }

  async search(q: string, type: string, limit: number, offset: number): Promise<SearchResponse> {
    const params = new HttpParams().set('q', q).set('type', type).set('limit', limit).set('offset', offset);
    const response = this.unwrap<{ results?: Partial<SearchResponse>; total?: SearchResponse['totals'] } & Partial<SearchResponse>>(
      await firstValueFrom(this.http.get(this.url('library_search'), { headers: await this.headers(), params })),
    );
    const results = response.results ?? response;
    return {
      tracks: (results.tracks ?? []).map(track => ({ ...track, duration: track.duration_seconds ?? track.duration })),
      artists: results.artists ?? [],
      albums: results.albums ?? [],
      playlists: results.playlists ?? [],
      genres: results.genres ?? [],
      totals: response.total ?? response.totals,
    };
  }

  async syncLibrary(): Promise<LibrarySyncResponse> {
    return this.unwrap<LibrarySyncResponse>(await firstValueFrom(
      this.http.post(this.url('library_sync'), {}, { headers: await this.headers() }),
    ));
  }

  async getArtists(q = '', limit = 60, offset = 0): Promise<{ items: SearchEntity[]; total: number }> {
    const params = new HttpParams().set('q', q).set('limit', limit).set('offset', offset);
    return this.unwrap<{ items: SearchEntity[]; total: number }>(await firstValueFrom(this.http.get(this.url('library_artists'), { headers: await this.headers(), params })));
  }

  async getAlbums(q = '', artistId = '', limit = 60, offset = 0): Promise<{ items: SearchEntity[]; total: number }> {
    let params = new HttpParams().set('q', q).set('limit', limit).set('offset', offset);
    if (artistId) params = params.set('artist_id', artistId);
    return this.unwrap<{ items: SearchEntity[]; total: number }>(await firstValueFrom(this.http.get(this.url('library_albums'), { headers: await this.headers(), params })));
  }

  async getLibraryStatistics(): Promise<LibraryStatistics> {
    return this.unwrap<LibraryStatistics>(await firstValueFrom(
      this.http.get(this.url('library_statistics'), { headers: await this.headers() }),
    ));
  }

  async getRandomPreferences(): Promise<RandomPreference[]> {
    return this.unwrap<{ items: RandomPreference[] }>(await firstValueFrom(
      this.http.get(this.url('library_preferences'), { headers: await this.headers() }),
    )).items ?? [];
  }

  async saveRandomPreference(preference: RandomPreference): Promise<RandomPreference> {
    return this.unwrap<RandomPreference>(await firstValueFrom(this.http.post(
      this.url('library_preference_save'), preference, { headers: await this.headers() },
    )));
  }

  async getRadios(): Promise<MusicRadio[]> {
    return this.unwrap<{ items: MusicRadio[] }>(await firstValueFrom(
      this.http.get(this.url('radios'), { headers: await this.headers() }),
    )).items ?? [];
  }

  async saveRadio(radio: { name: string; artist_ids: string[]; radio_id?: number }): Promise<MusicRadio> {
    return this.unwrap<{ item: MusicRadio }>(await firstValueFrom(this.http.post(
      this.url('radio_save'), radio, { headers: await this.headers() },
    ))).item;
  }

  async deleteRadio(radio_id: number): Promise<void> {
    await firstValueFrom(this.http.post(this.url('radio_delete'), { radio_id }, { headers: await this.headers() }));
  }

  async addTracks(track_ids: string[]): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add'), { track_ids }, { headers: await this.headers() }));
  }

  async addArtist(artist_id: string): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add_artist'), { artist_id }, { headers: await this.headers() }));
  }

  async addAlbum(album_id: string): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add_album'), { album_id }, { headers: await this.headers() }));
  }

  async replaceArtist(artist_id: string): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add_artist'), { artist_id, replace_queue: true }, { headers: await this.headers() }));
  }

  async replaceAlbum(album_id: string): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add_album'), { album_id, replace_queue: true }, { headers: await this.headers() }));
  }

  async addRadio(radio_id: number): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add_radio'), { radio_id }, { headers: await this.headers() }));
  }

  async replaceRadio(radio_id: number): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_add_radio'), { radio_id, replace_queue: true }, { headers: await this.headers() }));
  }

  async addRandomGroup(): Promise<{ artist: string }> {
    return this.unwrap<{ artist: string }>(await firstValueFrom(this.http.post(
      this.url('queue_add_random_group'), {}, { headers: await this.headers() },
    )));
  }

  async removeAt(index: number): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_remove'), { index }, { headers: await this.headers() }));
  }

  async reorder(from_index: number, to_index: number): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_reorder'), { from_index, to_index }, { headers: await this.headers() }));
  }

  async clearQueue(): Promise<void> {
    await firstValueFrom(this.http.post(this.url('queue_clear'), {}, { headers: await this.headers() }));
  }

  private async headers(): Promise<HttpHeaders> {
    return new HttpHeaders({ Authorization: `Bearer ${await this.auth.idToken()}` });
  }

  private url(path: string): string { return `${this.baseUrl}/${path}`; }

  private unwrap<T>(value: unknown): T {
    const body = value as { data?: T; error?: { code?: string; message?: string }; code?: string; message?: string };
    if (body?.error) throw new Error(`${body.error.code ?? 'API_ERROR'}${body.error.message ? `: ${body.error.message}` : ''}`);
    return (body?.data ?? value) as T;
  }
}
