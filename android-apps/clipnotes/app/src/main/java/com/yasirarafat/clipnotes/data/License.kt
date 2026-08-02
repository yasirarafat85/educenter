package com.yasirarafat.clipnotes.data

import android.content.Context
import android.provider.Settings
import android.util.Base64
import java.security.KeyFactory
import java.security.Signature
import java.security.spec.X509EncodedKeySpec

/**
 * Device-locked activation using public-key cryptography.
 *
 * The app holds only the PUBLIC key. Activation keys are RSA signatures over the
 * device code, produced by the matching PRIVATE key — which lives only in your
 * private key-generator tool. So keys can be created only by you and cannot be
 * forged even if someone decompiles the APK.
 *
 * A valid key is long (a base64 signature); deliver it by copy-paste, not typing.
 */
object License {

    private const val PUBLIC_KEY_B64 =
        "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAiTjkcrg3PPGs4w3UnfGFQe+qQGw7noqdd+5/TYQ0wl+bAf01JY6Kdee+kX33rdeOVlHl5EbUETQMEp1G6mFPjLud+Rj6iQ+mQo970eIbm26iqJy9lAblj9oZobnax0QzqFFY21BqOj7l6UPkIuThQb1FuZ4FAfnoF83iHJcckXvd5VBHqDx0OnUabkjJe4kwflZPK2Kz4fY6qqZAqjxd7+G/J0NDKtXKeXRCqEX8dGDQQWLAj1Mq4nlsfWZLG10IbAsVrFOIuDdrJfR6fIpq860toX9AHbsiTD5LxvAI7CPXdAVNOLCfpPtbdLPo3Db4wVhNnu4V+mfg/qw/96U/xQIDAQAB"

    /** Stable per-device code (same across uninstall/reinstall; changes on factory reset). */
    fun deviceCode(context: Context): String {
        val id = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        return (id ?: "UNKNOWN").uppercase()
    }

    fun isValidKey(context: Context, entered: String): Boolean {
        val keyText = entered.trim().replace(Regex("\\s"), "")
        if (keyText.isEmpty()) return false
        return try {
            val signature = Base64.decode(keyText, Base64.DEFAULT)
            val pubBytes = Base64.decode(PUBLIC_KEY_B64, Base64.DEFAULT)
            val publicKey = KeyFactory.getInstance("RSA")
                .generatePublic(X509EncodedKeySpec(pubBytes))
            val verifier = Signature.getInstance("SHA256withRSA")
            verifier.initVerify(publicKey)
            verifier.update(deviceCode(context).toByteArray(Charsets.UTF_8))
            verifier.verify(signature)
        } catch (e: Exception) {
            false
        }
    }
}
