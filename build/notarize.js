// Electron-builder calls this after signing (the NativePHP config sets afterSign to this file).
// Notarization is done in the release workflow with xcrun notarytool, so nothing happens here.
export default async function afterSign() {}
