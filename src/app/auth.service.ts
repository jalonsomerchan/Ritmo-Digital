import { Injectable, signal } from '@angular/core';
import {
  GoogleAuthProvider,
  User,
  getAuth,
  getRedirectResult,
  onAuthStateChanged,
  signInWithPopup,
  signInWithRedirect,
  signOut,
} from 'firebase/auth';
import { app, firebaseConfigured } from './firebase';

@Injectable({ providedIn: 'root' })
export class AuthService {
  readonly configured = firebaseConfigured;
  readonly user = signal<User | null>(null);
  readonly authError = signal('');
  private auth = app ? getAuth(app) : null;

  constructor() {
    if (this.auth) {
      void getRedirectResult(this.auth).catch(error => this.authError.set(this.message(error)));
    }
  }

  listen(callback: (user: User | null) => void): () => void {
    if (!this.auth) {
      callback(null);
      return () => undefined;
    }
    return onAuthStateChanged(this.auth, user => {
      this.user.set(user);
      if (user) this.authError.set('');
      callback(user);
    });
  }

  async signInWithGoogle(): Promise<void> {
    if (!this.auth) throw new Error('FIREBASE_NOT_CONFIGURED');
    this.authError.set('');
    const provider = new GoogleAuthProvider();
    const isIPadOS = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    const isMobile = isIPadOS || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (isMobile) await signInWithRedirect(this.auth, provider);
    else await signInWithPopup(this.auth, provider);
  }

  async signOut(): Promise<void> {
    if (this.auth) await signOut(this.auth);
  }

  async idToken(): Promise<string> {
    const user = this.user();
    if (!user) throw new Error('AUTH_REQUIRED');
    return user.getIdToken();
  }

  get email(): string { return this.user()?.email ?? ''; }

  message(error: unknown): string {
    const code = (error as { code?: string })?.code;
    const messages: Record<string, string> = {
      'auth/popup-closed-by-user': 'Se cerró la ventana de Google antes de completar el acceso.',
      'auth/popup-blocked': 'El navegador bloqueó la ventana de Google. Permite las ventanas emergentes e inténtalo de nuevo.',
      'auth/unauthorized-domain': 'Este dominio todavía no está autorizado en Firebase Authentication.',
      'auth/operation-not-allowed': 'Activa el proveedor Google en Firebase Authentication.',
      'auth/network-request-failed': 'No hay conexión con Google. Comprueba tu conexión e inténtalo de nuevo.',
      'auth/cancelled-popup-request': 'Ya hay una ventana de acceso abierta.',
    };
    return messages[code ?? ''] ?? 'No se ha podido iniciar sesión con Google. Inténtalo de nuevo.';
  }
}
