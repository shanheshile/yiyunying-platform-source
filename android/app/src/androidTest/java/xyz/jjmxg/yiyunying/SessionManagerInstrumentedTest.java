package xyz.jjmxg.yiyunying;

import android.content.Context;
import android.content.SharedPreferences;

import androidx.test.core.app.ApplicationProvider;
import androidx.test.ext.junit.runners.AndroidJUnit4;

import org.junit.Test;
import org.junit.runner.RunWith;

import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotEquals;
import static org.junit.Assert.assertTrue;

@RunWith(AndroidJUnit4.class)
public class SessionManagerInstrumentedTest {
    @Test
    public void encryptsTokensAndClearsAuthenticationScope() {
        Context context = ApplicationProvider.getApplicationContext();
        SessionManager session = new SessionManager(context);
        session.clearAuthentication();
        session.configureConnection("https://example.com", "app-one", "root");
        session.saveAuthenticated(Role.USER, "alice", "plain-access-token", "plain-refresh-token",
            "2099-01-01 00:00:00", "2099-02-01 00:00:00", "app-one", 88, 4);
        session.selectApp(9, "Test App", "app-one");

        assertTrue(session.isAuthenticated());
        assertEquals("plain-access-token", session.accessToken());
        assertEquals(88, session.actorId());
        SharedPreferences raw = context.getSharedPreferences("yiyunying.session.v1", Context.MODE_PRIVATE);
        assertNotEquals("plain-access-token", raw.getString("secure.access_token", ""));

        session.clearAuthentication();
        assertFalse(session.isAuthenticated());
        assertEquals(0, session.selectedAppId());
        assertEquals("", session.accessToken());
    }
}
