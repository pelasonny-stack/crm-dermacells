import { useState } from 'react';
import * as WebBrowser from 'expo-web-browser';
import * as AuthSession from 'expo-auth-session';
import * as Crypto from 'expo-crypto';
import { useAuthStore } from './auth-store';
import { getApiClient } from '@/api/client';

WebBrowser.maybeCompleteAuthSession();

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000/api/v1';

interface OAuthResult {
  success: boolean;
  error?: string;
}

/**
 * Generate a random PKCE code verifier (43-128 chars, URL-safe).
 */
async function generateCodeVerifier(): Promise<string> {
  const random = await Crypto.getRandomBytesAsync(32);
  return Buffer.from(random)
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=/g, '');
}

/**
 * SHA-256 code challenge from verifier.
 */
async function generateCodeChallenge(verifier: string): Promise<string> {
  const digest = await Crypto.digestStringAsync(
    Crypto.CryptoDigestAlgorithm.SHA256,
    verifier,
    { encoding: Crypto.CryptoEncoding.BASE64 }
  );
  return digest.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

function useOAuthFlow(provider: 'google' | 'microsoft') {
  const [isLoading, setIsLoading] = useState(false);
  const { setPendingToken, setUser } = useAuthStore();

  const redirectUri = AuthSession.makeRedirectUri({
    scheme: 'dermacells',
    path: 'oauth/callback',
  });

  const signIn = async (): Promise<OAuthResult> => {
    setIsLoading(true);
    try {
      const codeVerifier = await generateCodeVerifier();
      const codeChallenge = await generateCodeChallenge(codeVerifier);

      const clientId =
        provider === 'google'
          ? process.env.EXPO_PUBLIC_GOOGLE_CLIENT_ID ?? ''
          : process.env.EXPO_PUBLIC_MICROSOFT_CLIENT_ID ?? '';

      if (!clientId) {
        return {
          success: false,
          error: `${provider.toUpperCase()}_CLIENT_ID not configured in .env`,
        };
      }

      const authUrl = buildAuthUrl(provider, clientId, redirectUri, codeChallenge);

      const result = await WebBrowser.openAuthSessionAsync(authUrl, redirectUri);

      if (result.type !== 'success') {
        return { success: false, error: 'OAuth cancelled or failed' };
      }

      const url = new URL(result.url);
      const code = url.searchParams.get('code');
      if (!code) {
        return { success: false, error: 'No authorization code returned' };
      }

      // Exchange code for Sanctum PAT via backend
      const client = getApiClient(null);
      const res = await client.post<{ token: string; user: import('@/api/types').User }>(
        `/auth/oauth/${provider}`,
        {
          code,
          code_verifier: codeVerifier,
          redirect_uri: redirectUri,
        }
      );

      setPendingToken(res.data.token);
      setUser(res.data.user);
      return { success: true };
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Unknown error';
      return { success: false, error: message };
    } finally {
      setIsLoading(false);
    }
  };

  return { signIn, isLoading, redirectUri };
}

function buildAuthUrl(
  provider: 'google' | 'microsoft',
  clientId: string,
  redirectUri: string,
  codeChallenge: string
): string {
  const params = new URLSearchParams({
    client_id: clientId,
    redirect_uri: redirectUri,
    response_type: 'code',
    code_challenge: codeChallenge,
    code_challenge_method: 'S256',
    response_mode: 'query',
  });

  if (provider === 'google') {
    params.set('scope', 'openid email profile');
    params.set('access_type', 'online');
    return `https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`;
  } else {
    const tenant = process.env.EXPO_PUBLIC_MICROSOFT_TENANT_ID ?? 'common';
    params.set('scope', 'openid email profile User.Read');
    return `https://login.microsoftonline.com/${tenant}/oauth2/v2.0/authorize?${params.toString()}`;
  }
}

export function useOAuth() {
  const google = useOAuthFlow('google');
  const microsoft = useOAuthFlow('microsoft');

  return {
    signInWithGoogle: google.signIn,
    signInWithMicrosoft: microsoft.signIn,
    isLoading: google.isLoading || microsoft.isLoading,
  };
}
