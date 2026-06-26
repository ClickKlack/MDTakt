/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Basis-URL der MD-Takt-Engine-API, z. B. http://localhost:8000 */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
