package xyz.jjmxg.yiyunying.data.session;

import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

final class SecureValueStore {
    private static final String KEYSTORE = "AndroidKeyStore";
    private static final String ALIAS = "yiyunying.session.aes.v1";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";
    private static final int TAG_LENGTH_BITS = 128;
    private static final String FALLBACK_PREFIX = "private.v1:";

    private final SharedPreferences preferences;

    SecureValueStore(SharedPreferences preferences) {
        this.preferences = preferences;
    }

    synchronized void put(String key, String value) {
        if (value == null || value.isEmpty()) {
            preferences.edit().remove(key).apply();
            return;
        }
        try {
            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
            byte[] encrypted = cipher.doFinal(value.getBytes(StandardCharsets.UTF_8));
            String packed = Base64.encodeToString(cipher.getIV(), Base64.NO_WRAP)
                + ":" + Base64.encodeToString(encrypted, Base64.NO_WRAP);
            preferences.edit().putString(key, packed).apply();
        } catch (Exception exception) {
            // A small number of Android ROMs expose AndroidKeyStore but fail during key creation.
            // Keep the token in this app's private preferences so login never terminates the process.
            String fallback = FALLBACK_PREFIX + Base64.encodeToString(
                value.getBytes(StandardCharsets.UTF_8), Base64.NO_WRAP);
            preferences.edit().putString(key, fallback).apply();
        }
    }

    synchronized String get(String key) {
        String packed = preferences.getString(key, "");
        if (packed == null || packed.isEmpty()) {
            return "";
        }
        if (packed.startsWith(FALLBACK_PREFIX)) {
            try {
                byte[] decoded = Base64.decode(packed.substring(FALLBACK_PREFIX.length()), Base64.NO_WRAP);
                return new String(decoded, StandardCharsets.UTF_8);
            } catch (RuntimeException exception) {
                preferences.edit().remove(key).apply();
                return "";
            }
        }
        try {
            String[] parts = packed.split(":", 2);
            if (parts.length != 2) {
                throw new IllegalArgumentException("Invalid encrypted value");
            }
            byte[] iv = Base64.decode(parts[0], Base64.NO_WRAP);
            byte[] encrypted = Base64.decode(parts[1], Base64.NO_WRAP);
            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), new GCMParameterSpec(TAG_LENGTH_BITS, iv));
            return new String(cipher.doFinal(encrypted), StandardCharsets.UTF_8);
        } catch (Exception exception) {
            preferences.edit().remove(key).apply();
            return "";
        }
    }

    private SecretKey getOrCreateKey() throws Exception {
        KeyStore keyStore = KeyStore.getInstance(KEYSTORE);
        keyStore.load(null);
        java.security.Key existing = keyStore.getKey(ALIAS, null);
        if (existing instanceof SecretKey) {
            return (SecretKey) existing;
        }
        KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE);
        generator.init(new KeyGenParameterSpec.Builder(
            ALIAS,
            KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
        ).setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setRandomizedEncryptionRequired(true)
            .build());
        return generator.generateKey();
    }
}
