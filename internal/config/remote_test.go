package config

import "testing"

func TestManagerApplyVersioningAndSnapshot(t *testing.T) {
	m := NewManager()
	changed := m.Apply(RemoteConfig{
		Whitelist: []string{"A.COM", " b.com "},
		CFIPs:     []string{"104.16.1.1"},
		HTTPPort:  8080,
		HTTPSPort: 8443,
		Timestamp: 100,
	})
	if !changed {
		t.Fatal("expected first apply to change state")
	}

	s1 := m.Snapshot()
	if len(s1.Whitelist) != 2 {
		t.Fatalf("expected 2 whitelist entries, got %d", len(s1.Whitelist))
	}
	if _, ok := s1.Whitelist["a.com"]; !ok {
		t.Fatal("expected host normalization to lowercase")
	}

	changed = m.Apply(RemoteConfig{Timestamp: 100, CFIPs: []string{"1.1.1.1"}})
	if changed {
		t.Fatal("expected equal timestamp to be ignored")
	}

	changed = m.Apply(RemoteConfig{Timestamp: 101, Whitelist: []string{"new.com"}})
	if !changed {
		t.Fatal("expected newer timestamp to update")
	}
	s2 := m.Snapshot()
	if _, ok := s2.Whitelist["new.com"]; !ok {
		t.Fatal("expected updated whitelist")
	}
	if len(s2.CFIPs) != 1 || s2.CFIPs[0] != "1.1.1.1" {
		t.Fatalf("expected fallback cf ip, got %#v", s2.CFIPs)
	}
}
