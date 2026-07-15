const { chromium } = require("playwright");

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  try {
    console.log("📱 Abrindo homepage...");
    await page.goto("http://localhost:8080", { waitUntil: "networkidle" });

    // Verificar cores de debug (red, yellow)
    console.log("\n🔍 Validando cores...");
    const debugColors = await page.evaluate(() => {
      const allElements = document.querySelectorAll("*");
      const issues = [];

      for (const el of allElements) {
        const style = window.getComputedStyle(el);
        const bg = style.backgroundColor;
        const color = style.color;

        // Verificar cores de debug
        if (
          bg === "rgb(255, 0, 0)" ||
          bg === "rgb(255, 255, 0)" ||
          bg.includes("red") ||
          bg.includes("yellow")
        ) {
          issues.push({
            element: el.tagName,
            class: el.className,
            background: bg,
            text: el.textContent.substring(0, 50),
          });
        }
      }
      return issues;
    });

    if (debugColors.length > 0) {
      console.log("❌ ENCONTRADOS ELEMENTOS COM CORES DE DEBUG:");
      debugColors.forEach((el) => {
        console.log(`   ${el.element}.${el.class} = ${el.background}`);
        console.log(`      Texto: "${el.text}"`);
      });
    } else {
      console.log("✅ Nenhuma cor de debug encontrada");
    }

    // Validar seções principais
    console.log("\n📋 Validando seções...");
    const sections = await page.evaluate(() => {
      return {
        hasHero: !!document.querySelector(".hero"),
        hasHeroCarousel: !!document.querySelector(".hero-carousel-section"),
        hasCategories: !!document.querySelector(".home-categories"),
        hasProducts: !!document.querySelector(".home-products"),
        heroText: document.querySelector(".hero h1")?.textContent || "",
      };
    });

    console.log(`✅ Hero section: ${sections.hasHero ? "SIM" : "NÃO"}`);
    console.log(
      `✅ Hero carousel: ${sections.hasHeroCarousel ? "SIM" : "NÃO"}`
    );
    console.log(
      `✅ Categorias: ${sections.hasCategories ? "SIM" : "NÃO"}`
    );
    console.log(`✅ Produtos: ${sections.hasProducts ? "SIM" : "NÃO"}`);
    console.log(`\n📝 Título hero: "${sections.heroText.substring(0, 100)}..."`);

    // Tirar screenshot
    const filename = "test-results/homepage-validation.png";
    await page.screenshot({ path: filename, fullPage: true });
    console.log(`\n📸 Screenshot salvo: ${filename}`);

    // Resumo final
    console.log("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    const allGood = debugColors.length === 0 && sections.hasHero && sections.hasHeroCarousel;
    if (allGood) {
      console.log("✅ HOMEPAGE VALIDADA COM SUCESSO!");
    } else {
      console.log("❌ PROBLEMAS ENCONTRADOS - Revisar acima");
    }
    console.log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

  } catch (error) {
    console.error("❌ Erro durante teste:", error.message);
  } finally {
    await browser.close();
  }
})();
