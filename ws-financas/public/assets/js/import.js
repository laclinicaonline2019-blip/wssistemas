// Lê o armazenamento local do WS Finanças original (chave "wsfin:v1") quando o sistema roda no mesmo endereço.
(() => {
  let raw = null;
  try { raw = localStorage.getItem("wsfin:v1"); } catch (e) {}
  if (!raw) return;
  let n = 0; try { n = JSON.parse(raw).filter(x => !x.exemplo && x.tipo !== "config").length; } catch (e) { return; }
  if (!n) return;
  document.getElementById("local-box").hidden = false;
  document.getElementById("local-info").textContent = `Encontramos ${n} lançamento(s)/dívida(s) do WS Finanças antigo neste navegador.`;
  document.getElementById("use-local").addEventListener("click", () => {
    document.getElementById("conteudo").value = raw;
    document.getElementById("import-form").submit();
  });
})();
