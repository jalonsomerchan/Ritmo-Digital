import type { FirebaseOptions } from 'firebase/app';

export const environment = {
  production: false,
  apiBaseUrl: '',
  firebase: {
    apiKey: '',
    authDomain: '',
    projectId: '',
    appId: '',
  } satisfies FirebaseOptions,
};
