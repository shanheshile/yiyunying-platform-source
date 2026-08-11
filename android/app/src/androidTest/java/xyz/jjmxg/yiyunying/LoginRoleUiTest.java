package xyz.jjmxg.yiyunying;

import android.content.Context;

import androidx.test.core.app.ActivityScenario;
import androidx.test.core.app.ApplicationProvider;
import androidx.test.espresso.Espresso;
import androidx.test.ext.junit.runners.AndroidJUnit4;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;

import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;

import static androidx.test.espresso.assertion.ViewAssertions.matches;
import static androidx.test.espresso.matcher.ViewMatchers.withEffectiveVisibility;
import static androidx.test.espresso.matcher.ViewMatchers.withId;

@RunWith(AndroidJUnit4.class)
public class LoginRoleUiTest {
    @Before
    public void clearSession() {
        Context context = ApplicationProvider.getApplicationContext();
        new SessionManager(context).clearAuthentication();
    }

    @Test
    public void editionLocksRoleAndHidesProvisionedConnectionIdentity() {
        try (ActivityScenario<LoginActivity> ignored = ActivityScenario.launch(LoginActivity.class)) {
            Espresso.onView(withId(R.id.roleToggle)).check(matches(
                withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.GONE)));
            Espresso.onView(withId(R.id.serverLayout)).check(matches(
                withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.GONE)));
            Espresso.onView(withId(R.id.platformKeyLayout)).check(matches(
                withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.GONE)));
            Espresso.onView(withId(R.id.appKeyLayout)).check(matches(
                withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.GONE)));
            if (BuildConfig.FIXED_ROLE.equals("user")) {
                Espresso.onView(withId(R.id.registerButton)).check(matches(
                    withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.VISIBLE)));
            } else if (BuildConfig.FIXED_ROLE.equals("admin")) {
                Espresso.onView(withId(R.id.registerButton)).check(matches(
                    withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.VISIBLE)));
            } else {
                Espresso.onView(withId(R.id.registerButton)).check(matches(
                    withEffectiveVisibility(androidx.test.espresso.matcher.ViewMatchers.Visibility.GONE)));
            }
        }
    }
}
