package xyz.jjmxg.yiyunying.ui.main;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.RecyclerView;
import androidx.viewpager2.adapter.FragmentStateAdapter;
import androidx.viewpager2.widget.ViewPager2;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.databinding.FragmentManagementShellBinding;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.BackNavigationHandler;

public final class ManagementShellFragment extends BaseFragment implements BackNavigationHandler {
    private static final int[] MENU_IDS = {
        R.id.managementTabDashboard,
        R.id.managementTabOrganization,
        R.id.managementTabContent,
        R.id.managementTabMine,
    };
    private static final String[] TITLES = {"应用", "源码", "交流", "我的"};
    private FragmentManagementShellBinding binding;
    private final ViewPager2.OnPageChangeCallback pageChangeCallback =
        new ViewPager2.OnPageChangeCallback() {
            @Override public void onPageSelected(int position) {
                if (binding == null) return;
                if (binding.bottomNavigation.getSelectedItemId() != MENU_IDS[position]) {
                    binding.bottomNavigation.setSelectedItemId(MENU_IDS[position]);
                }
                host().setPageTitle(TITLES[position]);
            }
        };

    public static ManagementShellFragment newInstance() { return new ManagementShellFragment(); }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentManagementShellBinding.inflate(inflater, container, false);
        host().setMainChromeVisible(true);
        binding.pager.setAdapter(new PagerAdapter(this));
        binding.pager.setOffscreenPageLimit(1);
        tunePager();
        binding.pager.registerOnPageChangeCallback(pageChangeCallback);
        binding.bottomNavigation.setOnItemSelectedListener(item -> {
            int page = pageFor(item.getItemId());
            if (page < 0) return false;
            switchPage(page);
            return true;
        });
        host().setPageTitle(TITLES[0]);
        return binding.getRoot();
    }

    @Override public void onResume() {
        super.onResume();
        if (binding != null) host().setPageTitle(TITLES[binding.pager.getCurrentItem()]);
    }

    private static int pageFor(int menuId) {
        for (int index = 0; index < MENU_IDS.length; index++) {
            if (MENU_IDS[index] == menuId) return index;
        }
        return -1;
    }

    private void tunePager() {
        if (binding == null) return;
        View child = binding.pager.getChildAt(0);
        if (!(child instanceof RecyclerView)) return;
        RecyclerView recycler = (RecyclerView) child;
        recycler.setItemViewCacheSize(2);
        recycler.setItemAnimator(null);
        recycler.setHasFixedSize(true);
        recycler.setOverScrollMode(View.OVER_SCROLL_NEVER);
    }

    private void switchPage(int page) {
        if (binding == null) return;
        int current = binding.pager.getCurrentItem();
        if (current == page) return;
        binding.pager.setCurrentItem(page, Math.abs(current - page) == 1);
    }

    @Override public boolean onBackRequested() {
        // Every management bottom tab is a root destination and shares the global
        // double-back exit behavior owned by MainActivity.
        return false;
    }

    @Override public void onDestroyView() {
        binding.pager.unregisterOnPageChangeCallback(pageChangeCallback);
        binding = null;
        super.onDestroyView();
    }

    private static final class PagerAdapter extends FragmentStateAdapter {
        PagerAdapter(@NonNull Fragment fragment) { super(fragment); }

        @NonNull @Override public Fragment createFragment(int position) {
            if (position == 0) return FeatureDirectoryFragment.newEmbeddedInstance("apps");
            if (position == 1) return FeatureDirectoryFragment.newEmbeddedInstance("source");
            if (position == 2) return FeatureDirectoryFragment.newEmbeddedInstance("community");
            return FeatureDirectoryFragment.newEmbeddedInstance("account");
        }

        @Override public int getItemCount() { return 4; }
    }
}
