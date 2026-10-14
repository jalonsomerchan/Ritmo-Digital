import { initializeApp, type FirebaseOptions } from 'firebase/app';
import { environment } from '../environments/environment';

const config: FirebaseOptions = environment.firebase;

export const firebaseConfigured = Boolean(config.apiKey && config.authDomain && config.projectId && config.appId);
export const app = firebaseConfigured ? initializeApp(config) : null;
